<?php

namespace App\Command;

use App\Repository\IntegrationConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:enable-sharepoint-tools',
    description: 'Enable all SharePoint tools for existing integrations (removes auto-disabled tools)',
)]
class EnableSharePointToolsCommand extends Command
{
    public function __construct(
        private IntegrationConfigRepository $integrationConfigRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Enable SharePoint Tools');

        $configs = $this->integrationConfigRepository->findBy([
            'integrationType' => 'sharepoint',
        ]);

        if (empty($configs)) {
            $io->info('No SharePoint integrations found.');
            return Command::SUCCESS;
        }

        $toolsToRemove = [
            'sharepoint_list_files',
            'sharepoint_download_file',
            'sharepoint_get_list_items',
        ];

        $updated = 0;
        foreach ($configs as $config) {
            $disabledTools = $config->getDisabledTools();
            if (empty($disabledTools)) {
                continue;
            }

            $hasChanges = false;
            foreach ($toolsToRemove as $toolName) {
                if (in_array($toolName, $disabledTools, true)) {
                    $config->enableTool($toolName);
                    $hasChanges = true;
                }
            }

            if ($hasChanges) {
                $updated++;
                $userName = $config->getUser() ? $config->getUser()->getEmail() : 'unknown';
                $io->text(sprintf(
                    '  Enabled tools for "%s" (user: %s)',
                    $config->getName(),
                    $userName
                ));
            }
        }

        $this->entityManager->flush();

        if ($updated > 0) {
            $io->success(sprintf('Updated %d SharePoint integration(s).', $updated));
        } else {
            $io->info('No SharePoint integrations had disabled tools to enable.');
        }

        return Command::SUCCESS;
    }
}
