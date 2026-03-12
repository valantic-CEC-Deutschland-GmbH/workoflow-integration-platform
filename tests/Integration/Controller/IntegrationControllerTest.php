<?php

namespace App\Tests\Integration\Controller;

use App\DataFixtures\OrganisationTestFixtures;
use App\Entity\IntegrationConfig;
use App\Tests\Integration\AbstractIntegrationTestCase;
use App\Tests\Mock\TestHttpClientFactory;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

class IntegrationControllerTest extends AbstractIntegrationTestCase
{
    protected function getFixtures(): array
    {
        return [
            OrganisationTestFixtures::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset mock HTTP client overrides
        TestHttpClientFactory::reset();

        // Login as admin user by default
        $this->loginUser('admin@test.example.com');
    }

    protected function tearDown(): void
    {
        TestHttpClientFactory::reset();
        parent::tearDown();
    }

    // ========================================
    // RENDERING TESTS
    // ========================================

    public function testIndexPageDisplaysIntegrations(): void
    {
        $crawler = $this->client->request('GET', '/skills/');

        $this->assertResponseIsSuccessful();
        $this->assertPageTitleContains('Skills');

        // Check if integration table is displayed - look for the integration names from fixtures
        $this->assertSelectorExists('.integration-logo-small');
        $this->assertSelectorTextContains('body', 'Test JIRA Active');
    }

    public function testSetupPageRendersCorrectForm(): void
    {
        $crawler = $this->client->request('GET', '/skills/setup/jira');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Setup Jira');

        // Check form fields exist with Symfony form namespace
        $form = $crawler->filter('form')->form();
        $values = $form->getValues();

        // Symfony forms use namespace like "integration_config_type[name]"
        $hasNameField = false;
        $hasUrlField = false;
        $hasUsernameField = false;
        $hasApiTokenField = false;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $hasNameField = true;
            }
            if (str_contains($key, '[url]')) {
                $hasUrlField = true;
            }
            if (str_contains($key, '[username]')) {
                $hasUsernameField = true;
            }
            if (str_contains($key, '[api_token]')) {
                $hasApiTokenField = true;
            }
        }

        $this->assertTrue($hasNameField, 'Form should have name field');
        $this->assertTrue($hasUrlField, 'Form should have url field');
        $this->assertTrue($hasUsernameField, 'Form should have username field');
        $this->assertTrue($hasApiTokenField, 'Form should have api_token field');
    }

    // ========================================
    // CREATE TESTS
    // ========================================

    public function testCreateNewJiraIntegration(): void
    {
        // Mock HTTP client returns 200 for /rest/api/3/myself (connection test passes)
        $crawler = $this->client->request('GET', '/skills/setup/jira');

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names (they have namespace)
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Submit with test credentials (mock HTTP client will return success)
        $uniqueName = 'Test Jira Valid ' . uniqid();
        $form[$nameField] = $uniqueName;
        $form[$urlField] = 'https://test.atlassian.net';
        $form[$usernameField] = 'test@example.com';
        $form[$apiTokenField] = 'test-token-123';

        $this->client->submit($form);

        // Should redirect to integrations page on success
        $this->assertResponseRedirects('/skills/');

        // Follow the redirect
        $this->client->followRedirect();

        // Verify integration was created
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => $uniqueName]);

        $this->assertNotNull($config, 'Integration should be created');
        $this->assertEquals('jira', $config->getIntegrationType());
        $this->assertTrue($config->isActive());

        // Verify credentials are encrypted and stored
        $this->assertTrue($config->hasCredentials());

        // Cleanup
        $this->entityManager->remove($config);
        $this->entityManager->flush();
    }

    public function testCreateJiraIntegrationWithInvalidToken(): void
    {
        // Override mock HTTP client to simulate 401 for /rest/api/3/myself
        TestHttpClientFactory::setOverride(function (string $method, string $url): ?MockResponse {
            if (str_contains($url, '/rest/api/3/myself')) {
                return new MockResponse('{"message":"Unauthorized"}', [
                    'http_code' => 401,
                ]);
            }
            return null;
        });

        $crawler = $this->client->request('GET', '/skills/setup/jira');

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names (they have namespace)
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Submit with invalid token
        $form[$nameField] = 'Test Jira Invalid Token';
        $form[$urlField] = 'https://test.atlassian.net';
        $form[$usernameField] = 'test@example.com';
        $form[$apiTokenField] = 'INVALID-TOKEN-WRONG-12345';

        $this->client->submit($form);

        // Should return 200 or 422 with errors (not redirect)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY]),
            "Expected status code 200 or 422, got {$statusCode}"
        );

        // Check that the connection failed error is displayed
        $crawler = $this->client->getCrawler();

        // Look for global form errors or alert messages
        $alerts = $crawler->filter('.alert-danger');
        $this->assertGreaterThan(0, $alerts->count(), 'Should have danger alert');

        $errorText = $crawler->filter('body')->text();
        $this->assertStringContainsString('Could not establish connection', $errorText);

        // Verify no config was created
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test Jira Invalid Token']);

        $this->assertNull($config, 'Integration should not be created with invalid credentials');

        // Reset mock override
        TestHttpClientFactory::reset();
    }

    public function testCreateIntegrationWithDuplicateName(): void
    {
        // Get the current user for verification
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->findOneBy(['email' => 'admin@test.example.com']);

        // Clean up any existing test data
        $existingConfigs = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findBy(['name' => 'Duplicate Test Name']);

        foreach ($existingConfigs as $config) {
            $this->entityManager->remove($config);
        }
        $this->entityManager->flush();

        // First create an integration with a specific name
        $existing = $this->createTestIntegrationConfig('jira', 'Duplicate Test Name');

        // Try to create another with the same name
        $crawler = $this->client->request('GET', '/skills/setup/jira');

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names (they have namespace)
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Use the same name as existing
        $form[$nameField] = 'Duplicate Test Name';
        $form[$urlField] = 'https://different.atlassian.net';
        $form[$usernameField] = 'different@example.com';
        $form[$apiTokenField] = 'different-token-12345';

        $this->client->submit($form);

        // Symfony forms return 422 for validation errors, or 200 for manual validation
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY]),
            "Expected status code 200 or 422, got {$statusCode}"
        );

        // Check that the error is displayed in the form (Symfony form errors)
        $crawler = $this->client->getCrawler();
        // Symfony forms render errors in <ul> tags by default
        $errors = $crawler->filter('.form-group ul li, .invalid-feedback, .form-error-message');
        $this->assertGreaterThan(0, $errors->count(), 'Should have form errors');

        $errorText = $errors->text();
        $this->assertStringContainsString('An integration with this name already exists', $errorText);

        // Verify only one config exists with this name for this user
        $configs = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findBy([
                'name' => 'Duplicate Test Name',
                'user' => $user
            ]);

        $this->assertCount(1, $configs, 'Only one integration with this name should exist for this user');
    }


    // ========================================
    // UPDATE TESTS
    // ========================================

    public function testUpdateExistingIntegration(): void
    {
        // Use test credentials - no need for real ones since update doesn't re-validate
        $originalName = 'Original Integration Name ' . uniqid();
        $updatedName = 'Updated Integration Name ' . uniqid();

        // Create config with test credentials using helper method
        $testConfig = $this->createTestIntegrationConfig('jira', $originalName);
        $configId = $testConfig->getId();

        // Store original token for comparison
        $encryptionService = static::getContainer()->get('App\Service\EncryptionService');
        $originalCredentials = json_decode(
            $encryptionService->decrypt($testConfig->getEncryptedCredentials()),
            true
        );
        $originalToken = $originalCredentials['api_token'];

        // Open edit form
        $crawler = $this->client->request('GET', '/skills/setup/jira?instance=' . $configId);

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names (they have namespace)
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Update only the name, leave token empty (should keep existing token from DB)
        $form[$nameField] = $updatedName;
        // Keep URL and username from existing config (they are pre-filled in edit mode)
        // Form already has these values, we just don't change them
        // API token field is empty (masked in edit mode) - should keep existing value
        $form[$apiTokenField] = '';

        $this->client->submit($form);

        // Should redirect to integrations page on success
        $this->assertResponseRedirects('/skills/');

        // Follow the redirect
        $this->client->followRedirect();

        // Reload config from database
        $this->entityManager->clear();
        $updatedConfig = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);

        $this->assertNotNull($updatedConfig, 'Config should still exist');

        // Verify name was updated
        $this->assertEquals($updatedName, $updatedConfig->getName());

        // Verify credentials are still encrypted and token is unchanged
        $this->assertTrue($updatedConfig->hasCredentials());

        $updatedCredentials = json_decode(
            $encryptionService->decrypt($updatedConfig->getEncryptedCredentials()),
            true
        );

        // Token should be the same as before (not changed because field was empty)
        $this->assertEquals($originalToken, $updatedCredentials['api_token'], 'Token should remain unchanged when field is empty');

        // URL and username should remain unchanged (we didn't change them in the form)
        $this->assertEquals($originalCredentials['url'], $updatedCredentials['url']);
        $this->assertEquals($originalCredentials['username'], $updatedCredentials['username']);

        // Cleanup
        $this->entityManager->remove($updatedConfig);
        $this->entityManager->flush();
    }

    public function testEditFormShowsExistingValues(): void
    {
        // Create integration with known values
        $config = $this->createTestIntegrationConfig('jira', 'Test Integration');

        // Open edit form
        $crawler = $this->client->request('GET', '/skills/setup/jira?instance=' . $config->getId());

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names (they have namespace)
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Name should be visible
        $this->assertEquals('Test Integration', $form[$nameField]->getValue());

        // URL should be visible
        $this->assertEquals('https://test.atlassian.net', $form[$urlField]->getValue());

        // Email should be visible
        $this->assertEquals('test@example.com', $form[$usernameField]->getValue());

        // API token should be empty (masked)
        $this->assertEquals('', $form[$apiTokenField]->getValue());

        // But should show hint that value exists
        $this->assertSelectorTextContains('.form-text', 'Current value is stored securely');
    }

    public function testUrlNormalizationRemovesTrailingSlash(): void
    {
        // Mock HTTP client returns 200 for connection test
        $urlWithSlash = 'https://test.atlassian.net/';

        $crawler = $this->client->request('GET', '/skills/setup/jira');

        $form = $crawler->selectButton('Save Configuration')->form();

        // Find the correct form field names
        $values = $form->getValues();
        $nameField = null;
        $urlField = null;
        $usernameField = null;
        $apiTokenField = null;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $nameField = $key;
            }
            if (str_contains($key, '[url]')) {
                $urlField = $key;
            }
            if (str_contains($key, '[username]')) {
                $usernameField = $key;
            }
            if (str_contains($key, '[api_token]')) {
                $apiTokenField = $key;
            }
        }

        // Submit with URL that has trailing slash
        $uniqueName = 'Test URL Normalization ' . uniqid();
        $form[$nameField] = $uniqueName;
        $form[$urlField] = $urlWithSlash; // URL with trailing slash
        $form[$usernameField] = 'test@example.com';
        $form[$apiTokenField] = 'test-token-123';

        $this->client->submit($form);

        // Should redirect to integrations page on success
        $this->assertResponseRedirects('/skills/');

        // Verify integration was created and URL is normalized (no trailing slash)
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => $uniqueName]);

        $this->assertNotNull($config, 'Integration should be created');

        // Decrypt credentials to check the stored URL
        $encryptionService = static::getContainer()->get('App\Service\EncryptionService');
        $credentials = json_decode(
            $encryptionService->decrypt($config->getEncryptedCredentials()),
            true
        );

        // URL should be stored without trailing slash
        $this->assertEquals('https://test.atlassian.net', $credentials['url'], 'URL should be normalized without trailing slash');

        // Cleanup
        $this->entityManager->remove($config);
        $this->entityManager->flush();
    }

    // ========================================
    // DELETE TESTS
    // ========================================

    public function testDeleteIntegration(): void
    {
        $config = $this->createTestIntegrationConfig('jira', 'To Delete');
        $configId = $config->getId();

        $this->client->request('POST', '/skills/delete/' . $configId);

        $this->assertResponseRedirects('/skills/');

        // Should be deleted from database
        $deletedConfig = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);

        $this->assertNull($deletedConfig);
    }

    public function testCannotDeleteOtherUsersIntegration(): void
    {
        // Login as member user
        $this->loginUser('member@test.example.com');

        // Try to delete admin's integration
        $adminConfig = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test JIRA Active']);

        // Reload fixtures if not found (may have been deleted by previous test)
        if (!$adminConfig) {
            $this->reloadFixtures();
            $this->loginUser('member@test.example.com');
            $adminConfig = $this->entityManager
                ->getRepository(IntegrationConfig::class)
                ->findOneBy(['name' => 'Test JIRA Active']);
        }

        $this->assertNotNull($adminConfig, 'Test JIRA Active fixture should exist');

        $this->client->request('POST', '/skills/delete/' . $adminConfig->getId());

        $this->assertResponseRedirects('/skills/');

        // Config should still exist - reload from database
        $stillExists = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($adminConfig->getId());
        $this->assertNotNull($stillExists);
    }

    // ========================================
    // TOGGLE TESTS
    // ========================================

    public function testToggleIntegrationActivation(): void
    {
        // Use existing fixture integration "Test JIRA Active" which belongs to admin user
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test JIRA Active']);

        // Reload fixtures if not found (may have been deleted by previous test)
        if (!$config) {
            $this->reloadFixtures();
            $this->loginUser('admin@test.example.com');
            $config = $this->entityManager
                ->getRepository(IntegrationConfig::class)
                ->findOneBy(['name' => 'Test JIRA Active']);
        }

        $this->assertNotNull($config, 'Test JIRA Active fixture should exist');
        $configId = $config->getId();

        // Ensure it starts as active
        $this->assertTrue($config->isActive(), 'Integration should start as active');

        // Deactivate the integration - send as form data, not JSON
        $this->client->request('POST', '/skills/jira/toggle', [
            'active' => 'false',
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verify it's deactivated in database - need to reload from DB
        $this->entityManager->clear(); // Clear to get fresh data
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);
        $this->assertNotNull($config, 'Config should still exist');
        $this->assertFalse($config->isActive(), 'Config should be inactive after deactivation');

        // Activate it again
        $this->client->request('POST', '/skills/jira/toggle', [
            'active' => 'true',
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verify it's activated in database
        $this->entityManager->clear();
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);
        $this->assertNotNull($config, 'Config should still exist');
        $this->assertTrue($config->isActive(), 'Config should be active after activation');
    }

    public function testToggleToolEnableDisable(): void
    {
        // Use existing fixture integration "Test JIRA Active" which belongs to admin user
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test JIRA Active']);

        // Reload fixtures if not found (may have been deleted by previous test)
        if (!$config) {
            $this->reloadFixtures();
            $this->loginUser('admin@test.example.com');
            $config = $this->entityManager
                ->getRepository(IntegrationConfig::class)
                ->findOneBy(['name' => 'Test JIRA Active']);
        }

        $this->assertNotNull($config, 'Test JIRA Active fixture should exist');
        $configId = $config->getId();

        // The fixture has 'jira_get_sprint_issues' disabled, let's enable it first
        $this->assertTrue($config->isToolDisabled('jira_get_sprint_issues'), 'Tool should start as disabled from fixture');

        // Enable the tool that is disabled in fixture - send as form data, not JSON
        $this->client->request('POST', '/skills/jira/toggle-tool', [
            'tool' => 'jira_get_sprint_issues',
            'enabled' => 'true',
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verify the tool is enabled
        $this->entityManager->clear();
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);
        $this->assertNotNull($config, 'Config should still exist');
        $this->assertFalse($config->isToolDisabled('jira_get_sprint_issues'), 'Tool should now be enabled');

        // Disable it again
        $this->client->request('POST', '/skills/jira/toggle-tool', [
            'tool' => 'jira_get_sprint_issues',
            'enabled' => 'false',
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verify the tool is disabled again
        $this->entityManager->clear();
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($configId);
        $this->assertNotNull($config, 'Config should still exist');
        $this->assertTrue($config->isToolDisabled('jira_get_sprint_issues'), 'Tool should be disabled again');
    }

    public function testToggleToolRequiresValidInstance(): void
    {
        // Send without instance parameter - form data, not JSON
        $this->client->request('POST', '/skills/jira/toggle-tool', [
            'tool' => 'jira_search',
            'enabled' => 'true'
            // Omit instance parameter
        ]);

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('Instance ID is required', $response['error']);
    }

    public function testCannotAccessOtherOrganisationIntegration(): void
    {
        // Login as other organisation user
        $this->loginUser('other@test.example.com');

        // Try to access test organisation's integration
        $testOrgConfig = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test JIRA Active']);

        // Reload fixtures if not found (may have been deleted by previous test)
        if (!$testOrgConfig) {
            $this->reloadFixtures();
            $this->loginUser('other@test.example.com');
            $testOrgConfig = $this->entityManager
                ->getRepository(IntegrationConfig::class)
                ->findOneBy(['name' => 'Test JIRA Active']);
        }

        $this->assertNotNull($testOrgConfig, 'Test JIRA Active fixture should exist');

        // Try to edit
        $this->client->request('GET', '/skills/setup/jira?instance=' . $testOrgConfig->getId());
        $this->assertResponseRedirects('/skills/');

        // Try to delete
        $this->client->request('POST', '/skills/delete/' . $testOrgConfig->getId());
        $this->assertResponseRedirects('/skills/');

        // Config should still exist
        $stillExists = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->find($testOrgConfig->getId());

        $this->assertNotNull($stillExists);
    }

    // ========================================
    // TEST CONNECTION TESTS
    // ========================================

    public function testTestConnectionWithValidCredentials(): void
    {
        // Mock HTTP client returns 200 for /rest/api/3/myself by default
        $testConfig = $this->createTestIntegrationConfig('jira', 'Test Connection Valid ' . uniqid());
        $configId = $testConfig->getId();

        // Test the connection - send instance ID in POST body
        $this->client->request('POST', '/skills/jira/test', [
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success'], 'Connection with valid credentials should succeed');
        $this->assertEquals('Connection successful', $response['message']);

        // Cleanup - re-fetch since entity is detached after client request
        $configToDelete = $this->entityManager
            ->getRepository(\App\Entity\IntegrationConfig::class)
            ->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testTestConnectionWithInvalidCredentials(): void
    {
        // Override mock HTTP client to simulate 401 for /rest/api/3/myself
        TestHttpClientFactory::setOverride(function (string $method, string $url): ?MockResponse {
            if (str_contains($url, '/rest/api/3/myself')) {
                return new MockResponse('{"message":"Unauthorized"}', [
                    'http_code' => 401,
                ]);
            }
            return null;
        });

        $testConfig = $this->createTestIntegrationConfig('jira', 'Test Connection Invalid ' . uniqid());
        $configId = $testConfig->getId();

        // Test the connection - send instance ID in POST body
        $this->client->request('POST', '/skills/jira/test', [
            'instance' => (string) $configId
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($response['success'], 'Connection with invalid credentials should fail');

        // Cleanup - re-fetch since entity is detached after client request
        $configToDelete = $this->entityManager
            ->getRepository(\App\Entity\IntegrationConfig::class)
            ->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }

        // Reset mock override
        TestHttpClientFactory::reset();
    }
}
