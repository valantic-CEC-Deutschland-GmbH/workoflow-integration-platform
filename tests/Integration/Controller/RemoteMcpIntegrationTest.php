<?php

namespace App\Tests\Integration\Controller;

use App\DataFixtures\OrganisationTestFixtures;
use App\Entity\IntegrationConfig;
use App\Entity\UserOrganisation;
use App\Tests\Integration\AbstractIntegrationTestCase;
use App\Tests\Mock\TestHttpClientFactory;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * E2E tests for the Remote MCP Server integration feature.
 *
 * Tests cover:
 * - Skills page renders the Remote MCP Servers section
 * - Setup form has all required credential fields
 * - Create, edit, delete Remote MCP integrations
 * - Test connection (success & failure)
 * - MCP API returns discovered remote tools
 * - MCP API executes remote tools via fallback routing
 */
class RemoteMcpIntegrationTest extends AbstractIntegrationTestCase
{
    private string $validToken;

    protected function getFixtures(): array
    {
        return [
            OrganisationTestFixtures::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestHttpClientFactory::reset();
        $this->loginUser('admin@test.example.com');

        // Set up MCP API token
        $userOrg = $this->entityManager
            ->getRepository(UserOrganisation::class)
            ->findOneBy(['user' => $this->currentUser, 'organisation' => $this->currentOrganisation]);

        $this->assertNotNull($userOrg);
        /** @phpstan-ignore method.notFound */
        $this->validToken = $userOrg->regenerateToken();
        $this->entityManager->persist($userOrg);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        TestHttpClientFactory::reset();
        parent::tearDown();
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Create a Remote MCP IntegrationConfig with proper credentials.
     */
    private function createRemoteMcpConfig(string $name, bool $connected = true): IntegrationConfig
    {
        $config = new IntegrationConfig();
        $config->setOrganisation($this->currentOrganisation);
        $config->setUser($this->currentUser);
        $config->setIntegrationType('remote_mcp');
        $config->setName($name);
        $config->setActive(true);

        $encryptionService = static::getContainer()->get('App\Service\EncryptionService');
        $credentials = [
            'server_url' => 'https://mcp.example.com/mcp',
            'auth_type' => 'bearer',
            'auth_token' => 'test-bearer-token-123',
        ];
        $config->setEncryptedCredentials(
            $encryptionService->encrypt(json_encode($credentials))
        );

        if (!$connected) {
            $config->disconnect('Test disconnection');
        }

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    /**
     * Set up mock to simulate a successful MCP server (initialize + tools/list).
     */
    private function mockMcpServerSuccess(): void
    {
        TestHttpClientFactory::setOverride(function (string $method, string $url, array $options): ?MockResponse {
            if (!str_contains($url, 'mcp.example.com')) {
                return null;
            }

            $body = $options['body'] ?? '';
            if (is_string($body)) {
                $data = json_decode($body, true);
            } else {
                $data = [];
            }

            $rpcMethod = $data['method'] ?? '';

            // MCP initialize response
            if ($rpcMethod === 'initialize') {
                return new MockResponse(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $data['id'] ?? 1,
                    'result' => [
                        'protocolVersion' => '2025-03-26',
                        'capabilities' => ['tools' => []],
                        'serverInfo' => ['name' => 'Test MCP Server', 'version' => '1.0'],
                    ],
                ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
            }

            // MCP notifications/initialized (no response needed but return OK)
            if ($rpcMethod === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 200]);
            }

            // MCP tools/list response
            if ($rpcMethod === 'tools/list') {
                return new MockResponse(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $data['id'] ?? 3,
                    'result' => [
                        'tools' => [
                            [
                                'name' => 'get_weather',
                                'description' => 'Get weather for a city',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'city' => ['type' => 'string', 'description' => 'City name'],
                                    ],
                                    'required' => ['city'],
                                ],
                            ],
                            [
                                'name' => 'search_docs',
                                'description' => 'Search documentation',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'query' => ['type' => 'string', 'description' => 'Search query'],
                                    ],
                                    'required' => ['query'],
                                ],
                            ],
                        ],
                    ],
                ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
            }

            // MCP tools/call response
            if ($rpcMethod === 'tools/call') {
                $toolName = $data['params']['name'] ?? '';
                return new MockResponse(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $data['id'] ?? 4,
                    'result' => [
                        'content' => [
                            ['type' => 'text', 'text' => "Result from {$toolName}"],
                        ],
                    ],
                ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
            }

            return new MockResponse('{}', ['http_code' => 200]);
        });
    }

    /**
     * Set up mock to simulate a failing MCP server.
     */
    private function mockMcpServerFailure(): void
    {
        TestHttpClientFactory::setOverride(function (string $method, string $url): ?MockResponse {
            if (str_contains($url, 'mcp.example.com')) {
                return new MockResponse('Unauthorized', ['http_code' => 401]);
            }
            return null;
        });
    }

    // ========================================
    // SKILLS PAGE RENDERING TESTS
    // ========================================

    public function testSkillsPageRendersRemoteMcpSection(): void
    {
        // Create a Remote MCP config so the section has content
        $config = $this->createRemoteMcpConfig('Test MCP Server ' . uniqid());
        $configId = $config->getId();

        $crawler = $this->client->request('GET', '/skills/');

        $this->assertResponseIsSuccessful();

        // The remote_mcp_table component should render the "Remote MCP Servers" heading
        $pageText = $crawler->filter('body')->text();
        $this->assertStringContainsString('Remote MCP Servers', $pageText);

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testSkillsPageShowsRemoteMcpInDropdown(): void
    {
        $crawler = $this->client->request('GET', '/skills/');

        $this->assertResponseIsSuccessful();

        // The "Add New Integration" dropdown should contain Remote MCP Server option
        $dropdownItems = $crawler->filter('.dropdown-item');
        $found = false;
        foreach ($dropdownItems as $item) {
            if (str_contains($item->textContent, 'Remote MCP Server')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Dropdown should contain "Remote MCP Server" option');
    }

    // ========================================
    // SETUP FORM TESTS
    // ========================================

    public function testSetupPageRendersRemoteMcpForm(): void
    {
        $crawler = $this->client->request('GET', '/skills/setup/remote_mcp');

        $this->assertResponseIsSuccessful();

        // Check for Remote MCP Server in page title
        $pageText = $crawler->filter('body')->text();
        $this->assertStringContainsString('Remote MCP Server', $pageText);

        // Check form fields exist
        $form = $crawler->filter('form')->form();
        $values = $form->getValues();

        $hasNameField = false;
        $hasServerUrlField = false;
        $hasAuthTypeField = false;

        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $hasNameField = true;
            }
            if (str_contains($key, '[server_url]')) {
                $hasServerUrlField = true;
            }
            if (str_contains($key, '[auth_type]')) {
                $hasAuthTypeField = true;
            }
        }

        $this->assertTrue($hasNameField, 'Form should have name field');
        $this->assertTrue($hasServerUrlField, 'Form should have server_url field');
        $this->assertTrue($hasAuthTypeField, 'Form should have auth_type field');
    }

    // ========================================
    // CREATE TESTS
    // ========================================

    public function testCreateNewRemoteMcpIntegration(): void
    {
        // Mock successful MCP handshake for validation
        $this->mockMcpServerSuccess();

        $crawler = $this->client->request('GET', '/skills/setup/remote_mcp');
        $form = $crawler->selectButton('Save Configuration')->form();

        $values = $form->getValues();
        $fields = [];
        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $fields['name'] = $key;
            }
            if (str_contains($key, '[server_url]')) {
                $fields['server_url'] = $key;
            }
            if (str_contains($key, '[auth_type]')) {
                $fields['auth_type'] = $key;
            }
            if (str_contains($key, '[auth_token]')) {
                $fields['auth_token'] = $key;
            }
        }

        $uniqueName = 'My MCP Server ' . uniqid();
        $form[$fields['name']] = $uniqueName;
        $form[$fields['server_url']] = 'https://mcp.example.com/mcp';
        $form[$fields['auth_type']] = 'bearer';
        $form[$fields['auth_token']] = 'my-secret-token-123';

        $this->client->submit($form);

        // Should redirect to skills page on success
        $this->assertResponseRedirects('/skills/');
        $this->client->followRedirect();

        // Verify integration was created
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => $uniqueName]);

        $this->assertNotNull($config, 'Remote MCP integration should be created');
        $this->assertEquals('remote_mcp', $config->getIntegrationType());
        $this->assertTrue($config->isActive());
        $this->assertTrue($config->hasCredentials());

        // Verify stored credentials
        $encryptionService = static::getContainer()->get('App\Service\EncryptionService');
        $credentials = json_decode($encryptionService->decrypt($config->getEncryptedCredentials()), true);
        $this->assertEquals('https://mcp.example.com/mcp', $credentials['server_url']);
        $this->assertEquals('bearer', $credentials['auth_type']);
        $this->assertEquals('my-secret-token-123', $credentials['auth_token']);

        // Cleanup
        $this->entityManager->remove($config);
        $this->entityManager->flush();
    }

    public function testCreateRemoteMcpWithFailedValidation(): void
    {
        // Mock MCP server returning 401 (credential validation fails)
        $this->mockMcpServerFailure();

        $crawler = $this->client->request('GET', '/skills/setup/remote_mcp');
        $form = $crawler->selectButton('Save Configuration')->form();

        $values = $form->getValues();
        $fields = [];
        foreach (array_keys($values) as $key) {
            if (str_contains($key, '[name]')) {
                $fields['name'] = $key;
            }
            if (str_contains($key, '[server_url]')) {
                $fields['server_url'] = $key;
            }
            if (str_contains($key, '[auth_type]')) {
                $fields['auth_type'] = $key;
            }
            if (str_contains($key, '[auth_token]')) {
                $fields['auth_token'] = $key;
            }
        }

        $form[$fields['name']] = 'Bad MCP Server';
        $form[$fields['server_url']] = 'https://mcp.example.com/mcp';
        $form[$fields['auth_type']] = 'bearer';
        $form[$fields['auth_token']] = 'invalid-token';

        $this->client->submit($form);

        // Should stay on form page with error
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY]),
            "Expected status code 200 or 422, got {$statusCode}"
        );

        // Check for connection failed error
        $crawler = $this->client->getCrawler();
        $alerts = $crawler->filter('.alert-danger');
        $this->assertGreaterThan(0, $alerts->count(), 'Should show connection failed error');

        // Verify no config was created
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Bad MCP Server']);
        $this->assertNull($config, 'Integration should not be created with invalid credentials');
    }

    // ========================================
    // TEST CONNECTION
    // ========================================

    public function testTestConnectionSuccess(): void
    {
        $this->mockMcpServerSuccess();

        $config = $this->createRemoteMcpConfig('Test Connection MCP ' . uniqid());
        $configId = $config->getId();

        $this->client->request('POST', '/skills/remote_mcp/test', [
            'instance' => (string) $configId,
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success'], 'Connection test should succeed');
        $this->assertArrayHasKey('details', $response);
        $this->assertArrayHasKey('tested_endpoints', $response);

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testTestConnectionFailure(): void
    {
        $this->mockMcpServerFailure();

        $config = $this->createRemoteMcpConfig('Test Connection Fail MCP ' . uniqid());
        $configId = $config->getId();

        $this->client->request('POST', '/skills/remote_mcp/test', [
            'instance' => (string) $configId,
        ]);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($response['success'], 'Connection test should fail with bad credentials');

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    // ========================================
    // DELETE TEST
    // ========================================

    public function testDeleteRemoteMcpIntegration(): void
    {
        $config = $this->createRemoteMcpConfig('Delete Me MCP ' . uniqid());
        $configId = $config->getId();

        $this->client->request('POST', '/skills/delete/' . $configId);

        $this->assertResponseRedirects('/skills/');

        // Verify config was deleted
        $this->entityManager->clear();
        $deleted = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        $this->assertNull($deleted, 'Remote MCP config should be deleted');
    }

    // ========================================
    // MCP API TESTS
    // ========================================

    public function testMcpApiListsRemoteMcpTools(): void
    {
        $this->mockMcpServerSuccess();

        $config = $this->createRemoteMcpConfig('API Test MCP ' . uniqid());
        $configId = $config->getId();

        $this->client->request('GET', '/api/mcp/tools', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('tools', $data);

        // Find remote MCP tools in the response
        $remoteTools = array_filter($data['tools'], function (array $tool) use ($configId) {
            $name = $tool['function']['name'] ?? '';
            return str_ends_with($name, '_' . $configId);
        });

        $this->assertGreaterThanOrEqual(2, count($remoteTools), 'Should have at least 2 remote MCP tools (get_weather, search_docs)');

        // Verify tool names include config ID suffix
        $toolNames = array_map(fn(array $t) => $t['function']['name'], array_values($remoteTools));
        $this->assertContains('get_weather_' . $configId, $toolNames);
        $this->assertContains('search_docs_' . $configId, $toolNames);

        // Verify description includes server URL hint
        $firstTool = array_values($remoteTools)[0];
        $this->assertStringContainsString('mcp.example.com', $firstTool['function']['description']);

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testMcpApiDoesNotListDisconnectedRemoteMcpTools(): void
    {
        $this->mockMcpServerSuccess();

        $config = $this->createRemoteMcpConfig('Disconnected MCP ' . uniqid(), connected: false);
        $configId = $config->getId();

        $this->client->request('GET', '/api/mcp/tools', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Disconnected configs should not expose tools
        $remoteTools = array_filter($data['tools'], function (array $tool) use ($configId) {
            $name = $tool['function']['name'] ?? '';
            return str_ends_with($name, '_' . $configId);
        });

        $this->assertCount(0, $remoteTools, 'Disconnected Remote MCP should not expose tools');

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testMcpApiExecutesRemoteMcpTool(): void
    {
        $this->mockMcpServerSuccess();

        $config = $this->createRemoteMcpConfig('Execute Test MCP ' . uniqid());
        $configId = $config->getId();
        $toolId = 'get_weather_' . $configId;

        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => $toolId,
            'parameters' => ['city' => 'Berlin'],
        ]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success'], 'Remote MCP tool execution should succeed');
        $this->assertArrayHasKey('result', $data);

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }

    public function testMcpApiRejectsDisconnectedRemoteMcpExecution(): void
    {
        $config = $this->createRemoteMcpConfig('Disconnected Exec MCP ' . uniqid(), connected: false);
        $configId = $config->getId();
        $toolId = 'get_weather_' . $configId;

        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => $toolId,
            'parameters' => ['city' => 'Berlin'],
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should be 403 (disconnected) or 404 (tool not found since it's not listed)
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]),
            "Expected 403 or 404 for disconnected MCP, got {$statusCode}"
        );

        // Cleanup
        $configToDelete = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
        if ($configToDelete) {
            $this->entityManager->remove($configToDelete);
            $this->entityManager->flush();
        }
    }
}
