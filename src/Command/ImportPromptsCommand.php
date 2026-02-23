<?php

namespace App\Command;

use App\Entity\Prompt;
use App\Integration\IntegrationRegistry;
use App\Integration\PersonalizedSkillInterface;
use App\Repository\OrganisationRepository;
use App\Repository\PromptRepository;
use App\Repository\UserOrganisationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prompt:import',
    description: 'Import default prompts from CSV into the prompt vault for an organisation',
)]
class ImportPromptsCommand extends Command
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private UserOrganisationRepository $userOrganisationRepository,
        private PromptRepository $promptRepository,
        private EntityManagerInterface $entityManager,
        private IntegrationRegistry $integrationRegistry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'csv-file',
                InputArgument::REQUIRED,
                'Path to the CSV file containing prompts'
            )
            ->addOption(
                'org-uuid',
                null,
                InputOption::VALUE_REQUIRED,
                'Organisation UUID to import prompts into'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview changes without persisting to database'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvFile = $input->getArgument('csv-file');
        $orgUuid = $input->getOption('org-uuid');
        $dryRun = $input->getOption('dry-run');

        // Validate org-uuid is provided
        if ($orgUuid === null) {
            $io->error('The --org-uuid option is required');
            return Command::FAILURE;
        }

        // Validate CSV file exists
        if (!file_exists($csvFile) || !is_readable($csvFile)) {
            $io->error(sprintf('CSV file not found or not readable: %s', $csvFile));
            return Command::FAILURE;
        }

        // Find organisation
        $organisation = $this->organisationRepository->findByUuid($orgUuid);
        if ($organisation === null) {
            $io->error(sprintf('Organisation not found with UUID: %s', $orgUuid));
            return Command::FAILURE;
        }

        // Find first admin for ownership
        $adminUserOrg = $this->userOrganisationRepository->findFirstAdminByOrganisation($organisation);
        if ($adminUserOrg === null) {
            $io->error(sprintf('No admin user found for organisation: %s', $organisation->getName()));
            return Command::FAILURE;
        }
        $owner = $adminUserOrg->getUser();

        $io->title('Importing Prompts');
        $io->info([
            sprintf('CSV File: %s', $csvFile),
            sprintf('Organisation: %s (%s)', $organisation->getName(), $orgUuid),
            sprintf('Owner: %s', $owner->getEmail()),
            sprintf('Dry Run: %s', $dryRun ? 'Yes' : 'No'),
        ]);

        // Parse CSV
        $prompts = $this->parseCsv($csvFile);
        if (empty($prompts)) {
            $io->warning('No valid prompts found in CSV file');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d prompts in CSV', count($prompts)));
        $io->newLine();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $validCategories = array_keys(Prompt::getCategories());

        foreach ($prompts as $index => $promptData) {
            $lineNumber = $index + 2; // +2 for header and 0-based index

            // Validate required fields
            if (empty($promptData['import_key']) || empty($promptData['title']) || empty($promptData['content'])) {
                $errors[] = sprintf('Line %d: Missing required fields (import_key, title, or content)', $lineNumber);
                $skipped++;
                continue;
            }

            // Validate category (only if non-empty)
            if (!empty($promptData['category']) && !in_array($promptData['category'], $validCategories, true)) {
                $errors[] = sprintf(
                    'Line %d: Invalid category "%s". Valid: %s',
                    $lineNumber,
                    $promptData['category'],
                    implode(', ', $validCategories)
                );
                $skipped++;
                continue;
            }

            // Validate skill (only if non-empty)
            $skillValue = !empty($promptData['skill']) ? $promptData['skill'] : null;
            if ($skillValue !== null) {
                $integration = $this->integrationRegistry->get($skillValue);
                if ($integration === null || !$integration instanceof PersonalizedSkillInterface) {
                    $errors[] = sprintf(
                        'Line %d: Invalid skill "%s"',
                        $lineNumber,
                        $promptData['skill']
                    );
                    $skipped++;
                    continue;
                }
            }

            // Check for existing prompt (upsert logic)
            $existingPrompt = $this->promptRepository->findByImportKeyAndOrganisation(
                $promptData['import_key'],
                $organisation
            );

            if ($existingPrompt !== null) {
                // Update existing prompt
                $existingPrompt->setTitle($promptData['title']);
                $existingPrompt->setContent($promptData['content']);
                $existingPrompt->setDescription($promptData['description'] ?: null);
                $existingPrompt->setCategory($promptData['category'] ?: null);
                $existingPrompt->setSkill($skillValue);
                $updated++;
                $io->text(sprintf('  [UPDATE] %s', $promptData['title']));
            } else {
                // Create new prompt
                $prompt = new Prompt();
                $prompt->setImportKey($promptData['import_key']);
                $prompt->setTitle($promptData['title']);
                $prompt->setContent($promptData['content']);
                $prompt->setDescription($promptData['description'] ?: null);
                $prompt->setCategory($promptData['category'] ?: null);
                $prompt->setSkill($skillValue);
                $prompt->setScope(Prompt::SCOPE_ORGANISATION);
                $prompt->setOwner($owner);
                $prompt->setOrganisation($organisation);

                if (!$dryRun) {
                    $this->entityManager->persist($prompt);
                }
                $created++;
                $io->text(sprintf('  [CREATE] %s', $promptData['title']));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Display summary
        $io->newLine();
        $io->section('Import Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Created', (string) $created],
                ['Updated', (string) $updated],
                ['Skipped', (string) $skipped],
                ['Total Processed', (string) count($prompts)],
            ]
        );

        if (!empty($errors)) {
            $io->warning('Errors encountered:');
            foreach ($errors as $error) {
                $io->text('  - ' . $error);
            }
        }

        if ($dryRun) {
            $io->note('Dry run mode - no changes were persisted to database');
        } else {
            $io->success(sprintf(
                'Successfully imported %d prompts (%d created, %d updated)',
                $created + $updated,
                $created,
                $updated
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Parse CSV file and return array of prompt data
     * @return array<int, array{import_key: string, title: string, content: string, description: string, category: string, skill: string}>
     */
    private function parseCsv(string $filePath): array
    {
        $prompts = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return [];
        }

        // Read header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        // Normalize header keys (lowercase, trim)
        $headerMap = array_flip(array_map(
            fn ($h) => strtolower(trim($h)),
            $header
        ));

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($header)) {
                continue; // Skip malformed rows
            }

            $prompts[] = [
                'import_key' => trim($row[$headerMap['import_key'] ?? -1] ?? ''),
                'title' => trim($row[$headerMap['title'] ?? -1] ?? ''),
                'content' => trim($row[$headerMap['content'] ?? -1] ?? ''),
                'description' => trim($row[$headerMap['description'] ?? $headerMap['desc'] ?? -1] ?? ''),
                'category' => trim($row[$headerMap['category'] ?? -1] ?? ''),
                'skill' => trim($row[$headerMap['skill'] ?? -1] ?? ''),
            ];
        }

        fclose($handle);
        return $prompts;
    }
}
