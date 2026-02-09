<?php

namespace App\OAuth2;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * Candis.io OAuth2 Provider (Keycloak-based)
 *
 * Handles Candis-specific OAuth2 flow with Keycloak authorization server.
 * Organization is auto-discovered after token exchange via /v1/organizations/info.
 */
class CandisProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://id.my.candis.io/auth/realms/candis/protocol/openid-connect/auth';
    }

    /**
     * @param array<string, mixed> $params
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://id.my.candis.io/auth/realms/candis/protocol/openid-connect/token';
    }

    /**
     * @param AccessToken $token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return 'https://api.candis.io/v1/organizations/info';
    }

    /**
     * @return array<string>
     */
    protected function getDefaultScopes(): array
    {
        return ['exports', 'core_data', 'offline_access'];
    }

    /**
     * @return string
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * @param ResponseInterface $response
     * @param array<string, mixed>|string $data
     * @return void
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400) {
            $error = isset($data['error']) ? $data['error'] : 'Unknown error';
            $errorDescription = isset($data['error_description']) ? $data['error_description'] : '';
            throw new IdentityProviderException(
                sprintf('%s: %s', $error, $errorDescription),
                $response->getStatusCode(),
                $data
            );
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param AccessToken $token
     * @return ResourceOwnerInterface
     */
    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        return new CandisResourceOwner($response);
    }
}
