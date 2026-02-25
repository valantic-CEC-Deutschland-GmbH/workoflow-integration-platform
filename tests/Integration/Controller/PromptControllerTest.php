<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Prompt;
use App\Tests\Integration\AbstractIntegrationTestCase;

class PromptControllerTest extends AbstractIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginUser('admin@test.example.com');
    }

    // ========================================
    // LISTING & FILTER TESTS
    // ========================================

    public function testIndexPageRendersPlatformFilter(): void
    {
        $crawler = $this->client->request('GET', '/prompts/');

        $this->assertResponseIsSuccessful();

        // Platform filter dropdown should exist
        $platformSelect = $crawler->filter('select[name="platform"]');
        $this->assertCount(1, $platformSelect, 'Platform filter select should exist');

        // Should contain platform options
        $options = $platformSelect->filter('option');
        // +1 for the "All Platforms" placeholder option
        $this->assertGreaterThan(1, $options->count(), 'Platform filter should have options');
    }

    public function testIndexPagePreservesPlatformFilterInTabs(): void
    {
        $crawler = $this->client->request('GET', '/prompts/?scope=personal&platform=chatgpt');

        $this->assertResponseIsSuccessful();

        // The organisation tab link should preserve the platform parameter
        $orgTab = $crawler->filter('.tab-btn')->last();
        $href = $orgTab->attr('href');
        $this->assertStringContainsString('platform=chatgpt', $href);
    }

    public function testPlatformFilterFiltersPrompts(): void
    {
        // Create two prompts: one with chatgpt platform, one without
        $promptWithPlatform = $this->createTestPrompt('ChatGPT Prompt', 'chatgpt');
        $promptWithoutPlatform = $this->createTestPrompt('No Platform Prompt', null);

        // Filter by chatgpt
        $crawler = $this->client->request('GET', '/prompts/?scope=personal&platform=chatgpt');
        $this->assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('ChatGPT Prompt', $body);
        $this->assertStringNotContainsString('No Platform Prompt', $body);

        // Show all (no filter)
        $crawler = $this->client->request('GET', '/prompts/?scope=personal');
        $this->assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('ChatGPT Prompt', $body);
        $this->assertStringContainsString('No Platform Prompt', $body);

        // Cleanup
        $this->cleanupPrompt($promptWithPlatform);
        $this->cleanupPrompt($promptWithoutPlatform);
    }

    // ========================================
    // CREATE TESTS
    // ========================================

    public function testCreateFormShowsPlatformField(): void
    {
        $crawler = $this->client->request('GET', '/prompts/new');

        $this->assertResponseIsSuccessful();

        // Platform select field should exist
        $platformSelect = $crawler->filter('select[id$="_platform"]');
        $this->assertCount(1, $platformSelect, 'Platform select field should exist in create form');

        // Platform help text should exist
        $helpText = $crawler->filter('.form-label-tooltip');
        $this->assertGreaterThan(0, $helpText->count(), 'Platform tooltip icon should exist');
    }

    public function testCreatePromptWithPlatform(): void
    {
        $crawler = $this->client->request('GET', '/prompts/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save Prompt')->form();

        // Find field names
        $fields = $this->findPromptFormFields($form);

        $uniqueTitle = 'Test Platform Prompt ' . uniqid();
        $form[$fields['title']] = $uniqueTitle;
        $form[$fields['content']] = 'Test content for platform prompt';
        $form[$fields['platform']] = 'claude_code';

        $this->client->submit($form);

        // Should redirect to show page on success
        $this->assertTrue(
            $this->client->getResponse()->isRedirect(),
            'Should redirect after creating prompt'
        );

        // Verify prompt was created with platform
        $prompt = $this->entityManager
            ->getRepository(Prompt::class)
            ->findOneBy(['title' => $uniqueTitle]);

        $this->assertNotNull($prompt, 'Prompt should be created');
        $this->assertEquals('claude_code', $prompt->getPlatform());

        // Cleanup
        $this->cleanupPrompt($prompt);
    }

    public function testCreatePromptWithoutPlatform(): void
    {
        $crawler = $this->client->request('GET', '/prompts/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save Prompt')->form();

        $fields = $this->findPromptFormFields($form);

        $uniqueTitle = 'Test No Platform Prompt ' . uniqid();
        $form[$fields['title']] = $uniqueTitle;
        $form[$fields['content']] = 'Test content without platform';
        // Leave platform empty (default)

        $this->client->submit($form);

        $this->assertTrue(
            $this->client->getResponse()->isRedirect(),
            'Should redirect after creating prompt'
        );

        $prompt = $this->entityManager
            ->getRepository(Prompt::class)
            ->findOneBy(['title' => $uniqueTitle]);

        $this->assertNotNull($prompt, 'Prompt should be created');
        $this->assertNull($prompt->getPlatform(), 'Platform should be null when not set');

        $this->cleanupPrompt($prompt);
    }

    // ========================================
    // EDIT TESTS
    // ========================================

    public function testEditFormShowsExistingPlatform(): void
    {
        $prompt = $this->createTestPrompt('Edit Platform Test', 'gemini');

        $crawler = $this->client->request('GET', '/prompts/' . $prompt->getUuid() . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save Prompt')->form();
        $fields = $this->findPromptFormFields($form);

        // Platform should be pre-selected to 'gemini'
        $this->assertEquals('gemini', $form[$fields['platform']]->getValue());

        $this->cleanupPrompt($prompt);
    }

    public function testEditPromptChangePlatform(): void
    {
        $prompt = $this->createTestPrompt('Change Platform Test', 'chatgpt');
        $promptUuid = $prompt->getUuid();

        $crawler = $this->client->request('GET', '/prompts/' . $promptUuid . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save Prompt')->form();
        $fields = $this->findPromptFormFields($form);

        // Change platform from chatgpt to cursor
        $form[$fields['platform']] = 'cursor';
        $this->client->submit($form);

        $this->assertTrue($this->client->getResponse()->isRedirect());

        // Reload and verify
        $this->entityManager->clear();
        $updated = $this->entityManager
            ->getRepository(Prompt::class)
            ->findOneBy(['uuid' => $promptUuid]);

        $this->assertNotNull($updated);
        $this->assertEquals('cursor', $updated->getPlatform());

        $this->cleanupPrompt($updated);
    }

    // ========================================
    // SHOW / DETAIL TESTS
    // ========================================

    public function testShowPageDisplaysPlatformBadge(): void
    {
        $prompt = $this->createTestPrompt('Show Platform Badge Test', 'claude_desktop');

        $crawler = $this->client->request('GET', '/prompts/' . $prompt->getUuid());
        $this->assertResponseIsSuccessful();

        // Platform badge should be displayed
        $badge = $crawler->filter('.prompt-platform-badge');
        $this->assertCount(1, $badge, 'Platform badge should exist on show page');
        $this->assertStringContainsString('Claude Desktop', $badge->text());

        $this->cleanupPrompt($prompt);
    }

    public function testShowPageHidesNoPlatformBadge(): void
    {
        $prompt = $this->createTestPrompt('No Platform Badge Test', null);

        $crawler = $this->client->request('GET', '/prompts/' . $prompt->getUuid());
        $this->assertResponseIsSuccessful();

        // Platform badge should NOT be displayed
        $badge = $crawler->filter('.prompt-platform-badge');
        $this->assertCount(0, $badge, 'Platform badge should not exist when platform is null');

        $this->cleanupPrompt($prompt);
    }

    // ========================================
    // API HELPER SECTION
    // ========================================

    public function testApiHelperShowsPlatformCurlExample(): void
    {
        $crawler = $this->client->request('GET', '/prompts/');
        $this->assertResponseIsSuccessful();

        // The API helper section should contain a platform curl example
        $body = $crawler->filter('body')->html();
        $this->assertStringContainsString('curlPlatform', $body);
        $this->assertStringContainsString('platform=chatgpt', $body);
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestPrompt(string $title, ?string $platform): Prompt
    {
        $prompt = new Prompt();
        $prompt->setTitle($title);
        $prompt->setContent('Test content for ' . $title);
        $prompt->setPlatform($platform);
        $prompt->setScope(Prompt::SCOPE_PERSONAL);
        $prompt->setOwner($this->currentUser);
        $prompt->setOrganisation($this->currentOrganisation);

        $this->entityManager->persist($prompt);
        $this->entityManager->flush();

        return $prompt;
    }

    private function cleanupPrompt(Prompt $prompt): void
    {
        $fresh = $this->entityManager
            ->getRepository(Prompt::class)
            ->find($prompt->getId());

        if ($fresh) {
            $this->entityManager->remove($fresh);
            $this->entityManager->flush();
        }
    }

    /**
     * @return array<string, string>
     */
    private function findPromptFormFields(\Symfony\Component\DomCrawler\Form $form): array
    {
        $values = $form->getValues();
        $fields = [];

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[title]')) {
                $fields['title'] = $key;
            }
            if (str_contains($key, '[content]')) {
                $fields['content'] = $key;
            }
            if (str_contains($key, '[platform]')) {
                $fields['platform'] = $key;
            }
            if (str_contains($key, '[scope]')) {
                $fields['scope'] = $key;
            }
        }

        return $fields;
    }
}
