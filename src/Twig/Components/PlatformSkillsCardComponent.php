<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('platform_skills_card')]
class PlatformSkillsCardComponent
{
    /** @var array<int, array<string, mixed>> */
    public array $integrations = [];

    public ?string $webhookType = null;

    /**
     * Filter and return only system integrations
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSystemIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations,
            fn($integration) => $integration['isSystem'] ?? false
        ));
    }

    /**
     * Check if the organisation uses the common (ADK) orchestrator
     */
    public function isCommonOrchestrator(): bool
    {
        return $this->webhookType === 'COMMON';
    }

    /**
     * Check if system integrations exist
     */
    public function hasSystemIntegrations(): bool
    {
        return count($this->getSystemIntegrations()) > 0;
    }

    /**
     * Get count of system integrations
     */
    public function getSystemIntegrationsCount(): int
    {
        return count($this->getSystemIntegrations());
    }

    /**
     * Format last accessed date for display
     */
    public function formatLastAccessed(?\DateTimeInterface $date): string
    {
        if (!$date) {
            return '-';
        }

        $now = new \DateTime();
        $diff = $now->diff($date);

        if ($diff->days === 0) {
            if ($diff->h === 0) {
                return $diff->i === 0 ? 'Just now' : $diff->i . ' min ago';
            }
            return $diff->h . 'h ago';
        }

        if ($diff->days === 1) {
            return 'Yesterday';
        }

        if ($diff->days < 7) {
            return $diff->days . ' days ago';
        }

        return $date->format('d.m.Y H:i');
    }
}
