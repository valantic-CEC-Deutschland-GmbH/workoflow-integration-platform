<?php

namespace App\Tests\Unit\Service\Integration;

use App\Service\Integration\SharePointService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SharePointServiceScoringTest extends TestCase
{
    private SharePointService $service;

    protected function setUp(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $this->service = new SharePointService($httpClient);
    }

    public function testPolicyDocumentsScoreHigherThanContracts(): void
    {
        $results = [
            [
                'title' => 'Framework Agreement ATLIX valantic',
                'name' => 'Framework_Agreement_ATLIX_valantic.docx',
                'summary' => 'Rahmenvertrag mit Standort Mannheim Reisekosten',
                'description' => '',
                'lastModifiedDateTime' => '2026-01-15T10:00:00Z',
                'type' => 'file',
            ],
            [
                'title' => 'Reisekostenrichtlinie',
                'name' => 'Reisekostenrichtlinie.pdf',
                'summary' => 'Richtlinie für Reisekosten und Dienstreisen',
                'description' => 'HR Policy',
                'lastModifiedDateTime' => '2025-06-01T10:00:00Z',
                'type' => 'file',
            ],
            [
                'title' => 'HR Overview',
                'name' => 'HR.aspx',
                'summary' => 'Reisekostenrichtlinie als eigenen Menüpunkt',
                'description' => 'HR Overview page',
                'lastModifiedDateTime' => '2025-03-01T10:00:00Z',
                'type' => 'page',
            ],
        ];

        // Use reflection to call private method
        $method = new \ReflectionMethod(SharePointService::class, 'scoreResults');
        $scored = $method->invoke($this->service, $results, 'Reisekostenrichtlinie travel policy');

        // Policy document should score higher than contract
        $this->assertGreaterThan(
            $scored[1]['relevanceScore'], // Framework Agreement should be lower
            $scored[0]['relevanceScore'], // Reisekostenrichtlinie should be higher
            'Policy document should score higher than contract for policy query'
        );
    }

    public function testPolicyIndicatorWordsGetBoost(): void
    {
        $results = [
            [
                'title' => 'Urlaubsrichtlinie 2026',
                'name' => 'Urlaubsrichtlinie_2026.pdf',
                'summary' => 'Vacation policy for all employees',
                'description' => '',
                'lastModifiedDateTime' => '2026-01-01T10:00:00Z',
                'type' => 'file',
            ],
            [
                'title' => 'Project Update Meeting Notes',
                'name' => 'Meeting_Notes_2026.docx',
                'summary' => 'Discussion about vacation schedules and project timeline',
                'description' => '',
                'lastModifiedDateTime' => '2026-02-01T10:00:00Z',
                'type' => 'file',
            ],
        ];

        $method = new \ReflectionMethod(SharePointService::class, 'scoreResults');
        $scored = $method->invoke($this->service, $results, 'Urlaubsrichtlinie vacation policy');

        // Richtlinie doc should be first
        $this->assertEquals('Urlaubsrichtlinie 2026', $scored[0]['title']);
        $this->assertGreaterThan($scored[1]['relevanceScore'], $scored[0]['relevanceScore']);
    }

    public function testBoilerplateDetectionInSearchResults(): void
    {
        // Tests that when a search term appears in address-like patterns across
        // many results, it's flagged as potential boilerplate
        $results = [
            ['title' => 'Contract A', 'name' => 'Contract_A.docx', 'summary' => 'Reichskanzler-Müller-Straße 14 68165 Mannheim', 'description' => '', 'lastModifiedDateTime' => '2026-01-01T10:00:00Z', 'type' => 'file'],
            ['title' => 'Contract B', 'name' => 'Contract_B.docx', 'summary' => 'Standort Mannheim Reichskanzler-Müller-Straße', 'description' => '', 'lastModifiedDateTime' => '2026-01-01T10:00:00Z', 'type' => 'file'],
            ['title' => 'Offer C', 'name' => 'Offer_C.docx', 'summary' => '68165 Mannheim valantic GmbH', 'description' => '', 'lastModifiedDateTime' => '2026-01-01T10:00:00Z', 'type' => 'file'],
            ['title' => 'Contract D', 'name' => 'Contract_D.docx', 'summary' => 'Mannheim Reichskanzler-Müller-Straße 14', 'description' => '', 'lastModifiedDateTime' => '2026-01-01T10:00:00Z', 'type' => 'file'],
            ['title' => 'HR Policy', 'name' => 'Reisekostenrichtlinie.pdf', 'summary' => 'Mannheim Dienstreise Reisekosten policy', 'description' => '', 'lastModifiedDateTime' => '2025-06-01T10:00:00Z', 'type' => 'file'],
        ];

        $method = new \ReflectionMethod(SharePointService::class, 'detectBoilerplateTerms');
        $boilerplate = $method->invoke($this->service, $results, ['mannheim']);

        $this->assertContains(
            'mannheim',
            $boilerplate,
            'Mannheim should be detected as boilerplate when it appears in address-like patterns in most results'
        );
    }
}
