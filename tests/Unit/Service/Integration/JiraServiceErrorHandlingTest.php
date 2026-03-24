<?php

namespace App\Tests\Unit\Service\Integration;

use App\Service\Integration\JiraService;
use App\Service\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class JiraServiceErrorHandlingTest extends TestCase
{
    private array $credentials;

    protected function setUp(): void
    {
        $this->credentials = [
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token',
        ];
    }

    private function createService(MockHttpClient $httpClient): JiraService
    {
        return new JiraService($httpClient, new NullLogger(), new UrlNormalizer());
    }

    /**
     * When getCreateFieldMetadata fails (e.g. invalid project/issueType),
     * createIssue should throw RuntimeException with code 400 and helpful suggestion.
     */
    public function testCreateIssueWrapsMetadataFailureAs400(): void
    {
        $httpClient = new MockHttpClient([
            // getCreateFieldMetadata call returns 404
            new MockResponse(
                json_encode(['errorMessages' => ["Project 'INVALID' not found"], 'errors' => []]),
                ['http_code' => 404]
            ),
        ]);

        $service = $this->createService($httpClient);

        try {
            $service->createIssue($this->credentials, [
                'projectKey' => 'INVALID',
                'issueTypeId' => '10004',
                'summary' => 'Test issue',
                'customFields' => [
                    'customfield_13211' => ['id' => '11702'],
                ],
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals(400, $e->getCode(), 'Exception code should be 400');
            $this->assertStringContainsString(
                'Failed to prepare custom fields',
                $e->getMessage(),
                'Error message should indicate custom field preparation failure'
            );
            $this->assertStringContainsString(
                'jira_update_issue',
                $e->getMessage(),
                'Error message should suggest the create-then-update workaround'
            );
        }
    }

    /**
     * When getCreateFieldMetadata succeeds but formatCustomFieldValue throws
     * (e.g. invalid date format), createIssue should throw 400 with suggestion.
     */
    public function testCreateIssueWrapsFormatFailureAs400(): void
    {
        $metadataResponse = json_encode([
            'fields' => [
                [
                    'fieldId' => 'customfield_10100',
                    'schema' => ['type' => 'date', 'custom' => 'com.atlassian.jira.plugin.system.customfieldtypes:datepicker'],
                ],
            ],
        ]);

        $httpClient = new MockHttpClient([
            // getCreateFieldMetadata succeeds
            new MockResponse($metadataResponse, ['http_code' => 200]),
        ]);

        $service = $this->createService($httpClient);

        try {
            $service->createIssue($this->credentials, [
                'projectKey' => 'GH',
                'issueTypeId' => '1',
                'summary' => 'Test issue',
                'customFields' => [
                    'customfield_10100' => 'not-a-valid-date',
                ],
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals(400, $e->getCode(), 'Exception code should be 400');
            $this->assertStringContainsString(
                'Failed to prepare custom fields',
                $e->getMessage()
            );
        }
    }

    /**
     * When Jira API returns 400 for the actual create call (e.g. missing required field),
     * createIssue should throw RuntimeException with the original status code.
     */
    public function testCreateIssueForwardsJira400Error(): void
    {
        $httpClient = new MockHttpClient([
            // create issue call returns 400 (missing required field)
            new MockResponse(
                json_encode([
                    'errorMessages' => [],
                    'errors' => ['customfield_13211' => 'Testable is required'],
                ]),
                ['http_code' => 400]
            ),
        ]);

        $service = $this->createService($httpClient);

        try {
            $service->createIssue($this->credentials, [
                'projectKey' => 'GH',
                'issueTypeId' => '1',
                'summary' => 'Test without required field',
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals(400, $e->getCode(), 'Exception should preserve Jira 400 status code');
            $this->assertStringContainsString(
                'Jira API Error (HTTP 400)',
                $e->getMessage()
            );
            $this->assertStringContainsString(
                'customfield_13211',
                $e->getMessage(),
                'Error message should include the field that caused the error'
            );
        }
    }

    /**
     * createIssue without customFields should not trigger metadata fetch
     * and should succeed normally.
     */
    public function testCreateIssueWithoutCustomFieldsSucceeds(): void
    {
        $httpClient = new MockHttpClient([
            // create issue call succeeds
            new MockResponse(
                json_encode(['id' => '10001', 'key' => 'GH-999', 'self' => 'https://test.atlassian.net/rest/api/3/issue/10001']),
                ['http_code' => 201]
            ),
        ]);

        $service = $this->createService($httpClient);

        $result = $service->createIssue($this->credentials, [
            'projectKey' => 'GH',
            'issueTypeId' => '1',
            'summary' => 'Simple issue without custom fields',
        ]);

        $this->assertEquals('GH-999', $result['key']);
    }

    /**
     * When getIssueRaw or getCreateFieldMetadata fails during updateIssue,
     * it should throw RuntimeException with code 400.
     */
    public function testUpdateIssueWrapsMetadataFailureAs400(): void
    {
        $httpClient = new MockHttpClient([
            // getIssueRaw returns 404
            new MockResponse(
                json_encode(['errorMessages' => ["Issue Does Not Exist"], 'errors' => []]),
                ['http_code' => 404]
            ),
        ]);

        $service = $this->createService($httpClient);

        try {
            $service->updateIssue($this->credentials, 'INVALID-999', [
                'customFields' => [
                    'customfield_13211' => ['id' => '11702'],
                ],
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals(400, $e->getCode(), 'Exception code should be 400');
            $this->assertStringContainsString(
                'Failed to prepare custom fields for update',
                $e->getMessage()
            );
        }
    }
}
