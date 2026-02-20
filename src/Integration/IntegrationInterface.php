<?php

namespace App\Integration;

use App\Entity\IntegrationConfig;

interface IntegrationInterface
{
    /**
     * Get the unique identifier for this integration type
     */
    public function getType(): string;

    /**
     * Get human-readable name for this integration
     */
    public function getName(): string;

    /**
     * Get available tools for this integration
     * @return ToolDefinition[]
     */
    public function getTools(): array;

    /**
     * Execute a tool function
     * @param string $toolName The tool to execute
     * @param array $parameters The parameters for the tool
     * @param array $credentials The credentials (null for system tools)
     * @return array The result of the execution
     */
    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array;

    /**
     * Check if this integration requires credentials
     */
    public function requiresCredentials(): bool;

    /**
     * Validate credentials format
     */
    public function validateCredentials(array $credentials): bool;

    /**
     * Get credential fields needed for this integration
     * @return CredentialField[]
     */
    public function getCredentialFields(): array;

    /**
     * Check if this integration is experimental
     * Experimental integrations are shown with a warning badge in the UI
     */
    public function isExperimental(): bool;

    /**
     * Get setup instructions for this integration
     * Returns HTML content to display in the setup page, or null if no instructions needed
     *
     * @return string|null HTML content for setup instructions
     */
    public function getSetupInstructions(): ?string;

    /**
     * Get the path to the integration's logo image
     */
    public function getLogoPath(): string;
}
