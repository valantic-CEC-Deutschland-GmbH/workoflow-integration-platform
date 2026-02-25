<?php

namespace App\Tests\Integration\Api;

use App\Entity\UserOrganisation;
use App\Tests\Integration\AbstractIntegrationTestCase;

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
}
