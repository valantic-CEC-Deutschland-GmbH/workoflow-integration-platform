<?php

namespace App\Service;

use App\Entity\IntegrationConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ConnectionStatusService
{
    /**
     * Error patterns that indicate credential issues (case-insensitive).
     */
    private const CREDENTIAL_ERROR_PATTERNS = [
        'unauthorized',
        'invalid_grant',
        'invalid_client',
        'access_denied',
        'token expired',
        'token revoked',
        'aadsts',
        'consent_required',
        'api token',
        'authentication failed',
        'invalid credentials',
        'invalid api key',
        'invalid api token',
    ];

    /**
     * Patterns that indicate NON-credential failures (should NOT disconnect).
     */
    private const TEMPORARY_ERROR_PATTERNS = [
        'rate limit',
        'too many requests',
        'timeout',
        'connection refused',
        'service unavailable',
        'internal server error',
        'bad gateway',
        'network error',
        'dns',
        'could not resolve',
        'connection reset',
        'ssl',
        'tls',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogService $auditLogService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if an exception represents a credential failure.
     * Returns true only for REAL credential issues, not temporary failures.
     */
    public function isCredentialFailure(\Throwable $exception, string $integrationType): bool
    {
        $message = strtolower($exception->getMessage());

        // First, check if this is a temporary error - these should NOT disconnect
        foreach (self::TEMPORARY_ERROR_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                $this->logger->debug('Temporary error detected, not marking as credential failure', [
                    'pattern' => $pattern,
                    'message' => $message,
                ]);

                return false;
            }
        }

        // Handle HTTP client exceptions (4xx errors)
        if ($exception instanceof ClientExceptionInterface) {
            return $this->handleClientException($exception, $integrationType);
        }

        // 5xx server errors are NOT credential failures
        if ($exception instanceof ServerExceptionInterface) {
            return false;
        }

        // Transport exceptions (network issues) are NOT credential failures
        if ($exception instanceof TransportExceptionInterface) {
            return false;
        }

        // Check for credential error patterns in message
        foreach (self::CREDENTIAL_ERROR_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                $this->logger->info('Credential error pattern detected in exception message', [
                    'pattern' => $pattern,
                    'integration_type' => $integrationType,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Handle HTTP client exceptions (4xx errors).
     */
    private function handleClientException(ClientExceptionInterface $exception, string $integrationType): bool
    {
        $statusCode = $exception->getResponse()->getStatusCode();

        // 401 is always a credential failure
        if ($statusCode === 401) {
            $this->logger->info('401 Unauthorized - marking as credential failure', [
                'integration_type' => $integrationType,
            ]);

            return true;
        }

        // 403 needs context - check if it's access revoked vs resource permission
        if ($statusCode === 403) {
            return $this->is403CredentialFailure($exception, $integrationType);
        }

        return false;
    }

    /**
     * Determine if a 403 error represents revoked credentials vs resource-level permission.
     */
    private function is403CredentialFailure(ClientExceptionInterface $exception, string $integrationType): bool
    {
        try {
            $content = $exception->getResponse()->getContent(false);
            $message = strtolower($content);
        } catch (\Throwable) {
            $message = strtolower($exception->getMessage());
        }

        // Azure AD / SharePoint: Check for consent/token issues
        if ($integrationType === 'sharepoint') {
            if (
                str_contains($message, 'consent') ||
                str_contains($message, 'aadsts') ||
                str_contains($message, 'invalid_grant')
            ) {
                $this->logger->info('SharePoint 403 with OAuth error - credential failure', [
                    'integration_type' => $integrationType,
                ]);

                return true;
            }

            // Resource-level 403 is NOT a credential failure
            return false;
        }

        // Jira/Confluence: 403 on API token issues
        if (in_array($integrationType, ['jira', 'confluence'], true)) {
            if (
                str_contains($message, 'api token') ||
                str_contains($message, 'permission denied for api')
            ) {
                return true;
            }

            return false;
        }

        // GitLab: 403 can mean token scope insufficient
        if ($integrationType === 'gitlab') {
            if (
                str_contains($message, 'insufficient_scope') ||
                str_contains($message, 'forbidden')
            ) {
                return true;
            }

            return false;
        }

        // SAP C4C: 403 might indicate access revoked
        if ($integrationType === 'sap_c4c') {
            if (str_contains($message, 'not authorized')) {
                return true;
            }

            return false;
        }

        // Remote MCP: 403 with auth error patterns
        if ($integrationType === 'remote_mcp') {
            if (
                str_contains($message, 'unauthorized') ||
                str_contains($message, 'forbidden') ||
                str_contains($message, 'invalid token') ||
                str_contains($message, 'invalid api key')
            ) {
                return true;
            }

            return false;
        }

        // Default: 403 alone is NOT enough to disconnect
        return false;
    }

    /**
     * Mark integration as disconnected.
     */
    public function markDisconnected(IntegrationConfig $config, string $reason): void
    {
        // Truncate reason if too long
        $truncatedReason = mb_strlen($reason) > 500 ? mb_substr($reason, 0, 497) . '...' : $reason;

        $config->disconnect($truncatedReason);
        $this->entityManager->flush();

        $this->logger->warning('Integration marked as disconnected', [
            'integration_id' => $config->getId(),
            'type' => $config->getIntegrationType(),
            'name' => $config->getName(),
            'reason' => $truncatedReason,
        ]);

        $this->auditLogService->logWithOrganisation(
            'integration.disconnected',
            $config->getOrganisation(),
            $config->getUser(),
            [
                'integration_id' => $config->getId(),
                'integration_type' => $config->getIntegrationType(),
                'integration_name' => $config->getName(),
                'reason' => $truncatedReason,
            ]
        );
    }

    /**
     * Mark integration as reconnected (after credentials updated or test passed).
     */
    public function markReconnected(IntegrationConfig $config): void
    {
        $config->reconnect();
        $this->entityManager->flush();

        $this->logger->info('Integration reconnected', [
            'integration_id' => $config->getId(),
            'type' => $config->getIntegrationType(),
            'name' => $config->getName(),
        ]);

        $this->auditLogService->logWithOrganisation(
            'integration.reconnected',
            $config->getOrganisation(),
            $config->getUser(),
            [
                'integration_id' => $config->getId(),
                'integration_type' => $config->getIntegrationType(),
                'integration_name' => $config->getName(),
            ]
        );
    }
}
