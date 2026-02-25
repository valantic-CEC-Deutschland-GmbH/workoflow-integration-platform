<?php

namespace App\Tests\E2E\Service;

use App\Service\Integration\JiraService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * E2E Test for JiraService with real JIRA instance
 *
 * This test requires real JIRA credentials in .env.test.local:
 * - TEST_JIRA_URL
 * - TEST_JIRA_EMAIL
 * - TEST_JIRA_TOKEN
 *
 * Run with: vendor/bin/phpunit --group=live-e2e
 */
#[Group('live-e2e')]
class JiraServiceE2ETest extends KernelTestCase
{
    private JiraService $jiraService;
    private array $credentials;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var JiraService $jiraService */
        $jiraService = static::getContainer()->get(JiraService::class);
        $this->jiraService = $jiraService;

        // Load credentials from environment - skip if not set
        if (empty($_ENV['TEST_JIRA_URL']) || empty($_ENV['TEST_JIRA_EMAIL']) || empty($_ENV['TEST_JIRA_TOKEN'])) {
            $this->markTestSkipped(
                'JIRA E2E tests require real credentials in .env.test.local: ' .
                'TEST_JIRA_URL, TEST_JIRA_EMAIL, TEST_JIRA_TOKEN'
            );
        }

        // Skip if using dummy token from .env.test
        if ($_ENV['TEST_JIRA_TOKEN'] === 'dummy-token-for-testing') {
            $this->markTestSkipped('Real JIRA credentials not configured (using dummy token)');
        }

        $this->credentials = [
            'url' => $_ENV['TEST_JIRA_URL'],
            'username' => $_ENV['TEST_JIRA_EMAIL'],
            'api_token' => $_ENV['TEST_JIRA_TOKEN'],
        ];
    }

    /**
     * Test connection to real JIRA instance
     */
    public function testConnectionToRealJira(): void
    {
        $result = $this->jiraService->testConnection($this->credentials);

        $this->assertTrue(
            $result,
            'Could not connect to JIRA. Check your credentials in .env.test.local'
        );
    }

    /**
     * Test getting specific issue AZUBI-20 and validate its content
     */
    public function testGetIssueAzubi20WithValidation(): void
    {
        $issueKey = 'AZUBI-20';

        $result = $this->jiraService->getIssue($this->credentials, $issueKey);

        // Assert basic structure
        $this->assertArrayHasKey('key', $result, 'Response should have a key field');
        $this->assertArrayHasKey('fields', $result, 'Response should have a fields section');

        // Verify it's the correct issue
        $this->assertEquals($issueKey, $result['key'], "Issue key should be {$issueKey}");

        $fields = $result['fields'];

        // Validate Summary/Title
        $this->assertArrayHasKey('summary', $fields, 'Issue should have a summary');
        $this->assertEquals('Statistics View', $fields['summary'], 'Summary should be "Statistics View"');

        // Validate Story Points (customfield_10004 for this JIRA instance)
        $this->assertArrayHasKey('customfield_10004', $fields, 'Issue should have story points field');
        $this->assertEquals(8, $fields['customfield_10004'], 'Story points should be 8');

        // Validate User Story (customfield_12700)
        $this->assertArrayHasKey('customfield_12700', $fields, 'Issue should have User Story field');
        $userStory = $this->extractTextFromAdf($fields['customfield_12700']);
        $this->assertStringContainsString('BECAUSE', $userStory, 'User Story should contain "BECAUSE"');

        // Validate Acceptance Criteria (customfield_12903)
        $this->assertArrayHasKey('customfield_12903', $fields, 'Issue should have Acceptance Criteria field');
        $acceptanceCriteria = $this->extractTextFromAdf($fields['customfield_12903']);

        // Normalize text: replace non-breaking spaces and other unicode whitespace with regular spaces
        $acceptanceCriteria = preg_replace('/\s+/u', ' ', $acceptanceCriteria);

        // Check for key phrases in acceptance criteria (normalized for comparison)
        $this->assertStringContainsString('Nutzung der UMFZ ohne Personenbezug', $acceptanceCriteria);
        $this->assertStringContainsString('Mitfahrten in Firmen- oder Privatfahrzeugen', $acceptanceCriteria);
        $this->assertStringContainsString('Frequenz Mitfahrangebote', $acceptanceCriteria);
        $this->assertStringContainsString('Frequenz Mitfahrgesuche', $acceptanceCriteria);
        $this->assertStringContainsString('overview of the amounts of rides overall', $acceptanceCriteria);
        $this->assertStringContainsString('private cars', $acceptanceCriteria);
        $this->assertStringContainsString('company cars', $acceptanceCriteria);
        $this->assertStringContainsString('Registered users', $acceptanceCriteria);
    }

    /**
     * Test searching for issues in AZUBI project
     */
    public function testSearchAzubiProject(): void
    {
        $jql = 'project = AZUBI ORDER BY created DESC';

        $result = $this->jiraService->search($this->credentials, $jql, 5);

        // Assert response structure
        $this->assertArrayHasKey('issues', $result, 'Response should have issues array');

        // The new JQL API doesn't return total count, it uses pagination
        // Check that we got some results
        $this->assertNotEmpty($result['issues'], 'Issues array should not be empty');
        $this->assertGreaterThan(0, count($result['issues']), 'Should return at least one issue');
    }

    /**
     * Test getting available transitions for an issue
     */
    public function testGetAvailableTransitions(): void
    {
        $issueKey = 'GH-72';

        $result = $this->jiraService->getAvailableTransitions($this->credentials, $issueKey);

        // Assert response structure
        $this->assertArrayHasKey('transitions', $result, 'Response should have transitions array');
        $this->assertNotEmpty($result['transitions'], 'Should have at least one available transition');

        // Check transition structure
        $firstTransition = $result['transitions'][0];
        $this->assertArrayHasKey('id', $firstTransition, 'Transition should have id');
        $this->assertArrayHasKey('name', $firstTransition, 'Transition should have name');
        $this->assertArrayHasKey('to', $firstTransition, 'Transition should have to field');
        $this->assertArrayHasKey('name', $firstTransition['to'], 'Transition target should have name');
    }

    /**
     * Test transitioning an issue (without actually changing it to avoid test side effects)
     */
    public function testTransitionIssueValidation(): void
    {
        $issueKey = 'GH-72';

        // First, get available transitions
        $transitions = $this->jiraService->getAvailableTransitions($this->credentials, $issueKey);

        $this->assertNotEmpty($transitions['transitions'], 'Should have transitions to test with');
    }

    /**
     * Test getting detailed connection information
     */
    public function testConnectionDetailed(): void
    {
        $result = $this->jiraService->testConnectionDetailed($this->credentials);

        $this->assertArrayHasKey('success', $result, 'Result should have success field');
        $this->assertArrayHasKey('message', $result, 'Result should have message field');
        $this->assertArrayHasKey('details', $result, 'Result should have details field');
        $this->assertArrayHasKey('tested_endpoints', $result, 'Result should have tested_endpoints');

        $this->assertTrue($result['success'], 'Connection should be successful');
        $this->assertEquals('Connection successful', $result['message']);
    }

    /**
     * Helper method to extract plain text from Atlassian Document Format (ADF)
     * Recursively extracts text from nested structures
     */
    private function extractTextFromAdf(array $adf): string
    {
        $text = '';

        // If this node has text content, add it
        if (isset($adf['text'])) {
            $text .= $adf['text'] . ' ';
        }

        // Recursively process content array
        if (isset($adf['content']) && is_array($adf['content'])) {
            foreach ($adf['content'] as $child) {
                if (is_array($child)) {
                    $text .= $this->extractTextFromAdf($child);
                }
            }
        }

        return $text;
    }
}
