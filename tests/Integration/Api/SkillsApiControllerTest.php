<?php

namespace App\Tests\Integration\Api;

use App\Tests\Integration\AbstractIntegrationTestCase;

class SkillsApiControllerTest extends AbstractIntegrationTestCase
{
    private string $basicAuthHeader;

    protected function setUp(): void
    {
        parent::setUp();

        // Build Basic Auth header from test env vars
        $user = $_ENV['API_AUTH_USER'] ?? 'test-api-user';
        $password = $_ENV['API_AUTH_PASSWORD'] ?? 'test-api-password';
        $this->basicAuthHeader = 'Basic ' . base64_encode($user . ':' . $password);

        $this->loginUser('admin@test.example.com');
    }

    public function testGetSkillsWithValidAuth(): void
    {
        $this->client->request('GET', '/api/skills/', [], [], [
            'HTTP_AUTHORIZATION' => $this->basicAuthHeader,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        $data = json_decode($content, true);
        $this->assertIsArray($data);
    }

    public function testGetSkillsWithoutAuth(): void
    {
        $this->client->request('GET', '/api/skills/');

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());

        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        $data = json_decode($content, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Unauthorized', $data['error']);
    }

    public function testGetSkillsWithInvalidAuth(): void
    {
        $this->client->request('GET', '/api/skills/', [], [], [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('wrong:credentials'),
        ]);

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    public function testGetSkillsWithOrganisationUuid(): void
    {
        $orgUuid = $this->currentOrganisation->getUuid();

        $this->client->request('GET', '/api/skills/', [
            'organisation_uuid' => $orgUuid,
        ], [], [
            'HTTP_AUTHORIZATION' => $this->basicAuthHeader,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);
    }

    public function testGetSkillsWithInvalidOrganisationUuid(): void
    {
        $this->client->request('GET', '/api/skills/', [
            'organisation_uuid' => 'nonexistent-uuid',
        ], [], [
            'HTTP_AUTHORIZATION' => $this->basicAuthHeader,
        ]);

        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
    }
}
