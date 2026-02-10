<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('skills_table')]
class SkillsTableComponent
{
    /** @var array<int, array<string, mixed>> */
    public array $integrations = [];

    /** @var array<int, array<string, mixed>> */
    public array $availableTypes = [];

    /**
     * Filter and return only user integrations (non-system) that have credentials configured
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations,
            fn($integration) => !($integration['isSystem'] ?? false) && ($integration['hasCredentials'] ?? false)
        ));
    }

    /**
     * Check if there are any configured user integrations
     */
    public function hasConfiguredIntegrations(): bool
    {
        return count($this->getUserIntegrations()) > 0;
    }

    /**
     * Get table column definitions for display
     *
     * @return array<string, string>
     */
    public function getTableColumns(): array
    {
        return [
            'instance' => 'integration.instance_name',
            'type' => 'integration.type',
            'status' => 'integration.status',
            'last_accessed' => 'integration.last_accessed',
            'actions' => 'integration.actions',
        ];
    }

    /**
     * Format last accessed date for display
     */
    public function formatLastAccessed(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->format('d.m.Y H:i');
    }
}
