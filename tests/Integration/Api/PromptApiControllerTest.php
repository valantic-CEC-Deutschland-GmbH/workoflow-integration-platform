<?php

namespace App\Tests\Integration\Api;

use App\Entity\Prompt;
use App\Entity\UserOrganisation;
use App\Enum\PromptPlatform;
use App\Tests\Integration\AbstractIntegrationTestCase;

class PromptApiControllerTest extends AbstractIntegrationTestCase
{
    private string $validToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginUser('admin@test.example.com');

        // Set up a personal access token for API auth
        $userOrg = $this->entityManager
            ->getRepository(UserOrganisation::class)
            ->findOneBy(['user' => $this->currentUser, 'organisation' => $this->currentOrganisation]);

        $this->assertNotNull($userOrg, 'UserOrganisation should exist for test user');

        /** @phpstan-ignore method.notFound */
        $this->validToken = $userOrg->regenerateToken();
        $this->entityManager->persist($userOrg);
        $this->entityManager->flush();
    }

    // ========================================
    // PLATFORM FIELD IN RESPONSE
    // ========================================

    public function testApiResponseIncludesPlatformField(): void
    {
        $prompt = $this->createTestPrompt('API Platform Test', 'chatgpt', Prompt::SCOPE_PERSONAL);

        $this->client->request('GET', '/api/prompts', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('prompts', $data);
        $this->assertNotEmpty($data['prompts']);

        // Find our test prompt in the response
        $found = false;
        foreach ($data['prompts'] as $p) {
            if ($p['title'] === 'API Platform Test') {
                $found = true;
                $this->assertArrayHasKey('platform', $p);
                $this->assertEquals('chatgpt', $p['platform']);
                break;
            }
        }
        $this->assertTrue($found, 'Test prompt should be in API response');

        $this->cleanupPrompt($prompt);
    }

    public function testApiResponseIncludesNullPlatform(): void
    {
        $prompt = $this->createTestPrompt('API No Platform Test', null, Prompt::SCOPE_PERSONAL);

        $this->client->request('GET', '/api/prompts', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $found = false;
        foreach ($data['prompts'] as $p) {
            if ($p['title'] === 'API No Platform Test') {
                $found = true;
                $this->assertArrayHasKey('platform', $p);
                $this->assertNull($p['platform']);
                break;
            }
        }
        $this->assertTrue($found, 'Test prompt should be in API response');

        $this->cleanupPrompt($prompt);
    }

    // ========================================
    // PLATFORM FILTER
    // ========================================

    public function testApiFilterByPlatform(): void
    {
        $promptChatgpt = $this->createTestPrompt('Filter ChatGPT', 'chatgpt', Prompt::SCOPE_PERSONAL);
        $promptCursor = $this->createTestPrompt('Filter Cursor', 'cursor', Prompt::SCOPE_PERSONAL);
        $promptNone = $this->createTestPrompt('Filter None', null, Prompt::SCOPE_PERSONAL);

        // Filter by chatgpt
        $this->client->request('GET', '/api/prompts?platform=chatgpt', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $titles = array_column($data['prompts'], 'title');

        $this->assertContains('Filter ChatGPT', $titles);
        $this->assertNotContains('Filter Cursor', $titles);
        $this->assertNotContains('Filter None', $titles);

        // Verify meta includes platform
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals('chatgpt', $data['meta']['platform']);

        $this->cleanupPrompt($promptChatgpt);
        $this->cleanupPrompt($promptCursor);
        $this->cleanupPrompt($promptNone);
    }

    public function testApiFilterByPlatformWithNoResults(): void
    {
        // Filter by a platform with no prompts
        $this->client->request('GET', '/api/prompts?platform=windsurf', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('prompts', $data);
        $this->assertEmpty($data['prompts']);
        $this->assertEquals(0, $data['meta']['total']);
    }

    // ========================================
    // VALIDATION
    // ========================================

    public function testApiRejectsInvalidPlatform(): void
    {
        $this->client->request('GET', '/api/prompts?platform=nonexistent_platform', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Invalid platform parameter', $data['error']);
        $this->assertArrayHasKey('valid_values', $data);
        $this->assertContains('chatgpt', $data['valid_values']);
        $this->assertContains('claude_code', $data['valid_values']);
    }

    public function testApiAcceptsAllValidPlatformValues(): void
    {
        foreach (PromptPlatform::cases() as $platform) {
            $this->client->request('GET', '/api/prompts?platform=' . $platform->value, [], [], [
                'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            ]);

            $this->assertResponseIsSuccessful(
                "Platform '{$platform->value}' should be accepted by API"
            );
        }
    }

    // ========================================
    // COMBINED FILTERS
    // ========================================

    public function testApiCombinePlatformWithScopeFilter(): void
    {
        $personalPrompt = $this->createTestPrompt('Personal ChatGPT', 'chatgpt', Prompt::SCOPE_PERSONAL);
        $orgPrompt = $this->createTestPrompt('Org ChatGPT', 'chatgpt', Prompt::SCOPE_ORGANISATION);

        // Filter by platform + personal scope
        $this->client->request('GET', '/api/prompts?platform=chatgpt&scope=personal', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $titles = array_column($data['prompts'], 'title');

        $this->assertContains('Personal ChatGPT', $titles);
        $this->assertNotContains('Org ChatGPT', $titles);

        $this->cleanupPrompt($personalPrompt);
        $this->cleanupPrompt($orgPrompt);
    }

    // ========================================
    // AUTH TESTS
    // ========================================

    public function testApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/prompts?platform=chatgpt');

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestPrompt(string $title, ?string $platform, string $scope): Prompt
    {
        $prompt = new Prompt();
        $prompt->setTitle($title);
        $prompt->setContent('Test content for ' . $title);
        $prompt->setPlatform($platform);
        $prompt->setScope($scope);
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
}
