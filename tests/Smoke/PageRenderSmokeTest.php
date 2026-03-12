<?php

namespace App\Tests\Smoke;

use App\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PageRenderSmokeTest extends AbstractIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginUser('admin@test.example.com');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function authenticatedPageProvider(): array
    {
        return [
            'skills listing' => ['/skills/'],
            'my-agent' => ['/my-agent'],
            'prompts' => ['/prompts/'],
            'release notes' => ['/release-notes/'],
        ];
    }

    #[DataProvider('authenticatedPageProvider')]
    public function testAuthenticatedPageRenders(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful(
            "Page {$url} should render without errors"
        );
    }

    public function testHealthEndpointWithoutAuth(): void
    {
        // Health endpoint does not require authentication - use existing client
        $this->client->request('GET', '/health');

        // Health may return 200 or 503 depending on services, but should not 500
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [200, 503],
            "Health endpoint should return 200 or 503, got {$statusCode}"
        );

        // Response should be valid JSON
        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        $data = json_decode($content, true);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('checks', $data);
    }
}
