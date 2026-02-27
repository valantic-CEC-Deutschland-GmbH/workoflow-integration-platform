<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('remote_mcp_table')]
class RemoteMcpTableComponent
{
    /** @var array<int, array<string, mixed>> */
    public array $integrations = [];

    /**
     * Filter and return only remote_mcp integrations that have credentials configured
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRemoteMcpIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations,
            fn($integration) => ($integration['type'] ?? '') === 'remote_mcp' && ($integration['hasCredentials'] ?? false)
        ));
    }

    /**
     * Check if there are any configured remote MCP integrations
     */
    public function hasConfiguredIntegrations(): bool
    {
        return count($this->getRemoteMcpIntegrations()) > 0;
    }
}
