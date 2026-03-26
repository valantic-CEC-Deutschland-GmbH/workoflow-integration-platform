<?php

namespace App\Tests\Integration\Api;

use App\Entity\IntegrationConfig;
use App\Entity\UserOrganisation;
use App\Tests\Integration\AbstractIntegrationTestCase;
use App\Tests\Mock\TestHttpClientFactory;
use Symfony\Component\HttpClient\Response\MockResponse;

class McpApiControllerTest extends AbstractIntegrationTestCase
{
    private string $validToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginUser('admin@test.example.com');

        // Set up a personal access token for MCP API auth
        $userOrg = $this->entityManager
            ->getRepository(UserOrganisation::class)
            ->findOneBy(['user' => $this->currentUser, 'organisation' => $this->currentOrganisation]);

        $this->assertNotNull($userOrg, 'UserOrganisation should exist for test user');

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

    public function testGetToolsWithValidToken(): void
    {
        $this->client->request('GET', '/api/mcp/tools', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        $data = json_decode($content, true);
        $this->assertIsArray($data);
    }

    public function testGetToolsWithoutToken(): void
    {
        $this->client->request('GET', '/api/mcp/tools');

        // Without a token, the MCP controller returns 401 JSON
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertEquals(401, $statusCode);
    }

    public function testGetToolsWithInvalidToken(): void
    {
        $this->client->request('GET', '/api/mcp/tools', [], [], [
            'HTTP_X_PROMPT_TOKEN' => 'invalid-token-that-does-not-exist',
        ]);

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    public function testGetToolsWithBearerAuth(): void
    {
        $this->client->request('GET', '/api/mcp/tools', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->validToken,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testExecuteToolWithoutToken(): void
    {
        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => 'nonexistent_tool',
            'parameters' => [],
        ]));

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * When a tool execution throws a RuntimeException with code 400,
     * the MCP endpoint should return HTTP 400 (not 500).
     */
    public function testExecuteToolReturns400OnClientError(): void
    {
        // Find the active Jira integration config for the admin user
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy([
                'user' => $this->currentUser,
                'organisation' => $this->currentOrganisation,
                'integrationType' => 'jira',
                'active' => true,
            ]);
        $this->assertNotNull($config, 'Active Jira config should exist from fixtures');

        // Mock Jira API to return 400 (invalid JQL)
        TestHttpClientFactory::setOverride(function (string $method, string $url, array $options): ?MockResponse {
            if (str_contains($url, '/rest/api/3/search/jql')) {
                return new MockResponse(
                    json_encode([
                        'errorMessages' => ['JQL query is invalid'],
                        'errors' => [],
                    ]),
                    ['http_code' => 400]
                );
            }

            return null;
        });

        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => 'jira_search_' . $config->getId(),
            'parameters' => [
                'jql' => 'INVALID JQL',
            ],
        ]));

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(400, $statusCode, 'MCP endpoint should return 400 for Jira client errors, not 500');
        $this->assertFalse($data['success']);
        $this->assertEquals(400, $data['error_code']);
    }

    /**
     * When a tool execution throws a RuntimeException with code 404,
     * the MCP endpoint should return HTTP 404.
     */
    public function testExecuteToolReturns404OnNotFound(): void
    {
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy([
                'user' => $this->currentUser,
                'organisation' => $this->currentOrganisation,
                'integrationType' => 'jira',
                'active' => true,
            ]);
        $this->assertNotNull($config);

        // Mock Jira API to return 404
        TestHttpClientFactory::setOverride(function (string $method, string $url, array $options): ?MockResponse {
            if (str_contains($url, '/rest/api/3/issue/')) {
                return new MockResponse(
                    json_encode([
                        'errorMessages' => ['Issue does not exist or you do not have permission to see it.'],
                        'errors' => [],
                    ]),
                    ['http_code' => 404]
                );
            }

            return null;
        });

        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => 'jira_get_issue_' . $config->getId(),
            'parameters' => [
                'issueKey' => 'NONEXISTENT-999',
            ],
        ]));

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(404, $statusCode, 'MCP endpoint should return 404 for Jira not-found errors');
        $this->assertFalse($data['success']);
        $this->assertEquals(404, $data['error_code']);
    }

    /**
     * Successful tool execution should still return 200.
     */
    public function testExecuteToolReturns200OnSuccess(): void
    {
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy([
                'user' => $this->currentUser,
                'organisation' => $this->currentOrganisation,
                'integrationType' => 'jira',
                'active' => true,
            ]);
        $this->assertNotNull($config);

        $this->client->request('POST', '/api/mcp/execute', [], [], [
            'HTTP_X_PROMPT_TOKEN' => $this->validToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_id' => 'jira_search_' . $config->getId(),
            'parameters' => [
                'jql' => 'project = GH',
            ],
        ]));

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $statusCode, 'Successful execution should return 200');
        $this->assertTrue($data['success']);
    }
}
