<?php

namespace App\OAuth2;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Candis Resource Owner (organization info)
 */
class CandisResourceOwner implements ResourceOwnerInterface
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        protected array $response
    ) {
    }

    /**
     * Get organization ID
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->response['id'] ?? null;
    }

    /**
     * Get organization name
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->response['name'] ?? null;
    }

    /**
     * Return all response data
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }
}
