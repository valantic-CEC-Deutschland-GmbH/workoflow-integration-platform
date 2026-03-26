<?php

namespace App\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use InvalidArgumentException;

class UrlNormalizer
{
    /**
     * Normalize a URL: parse, remove trailing slashes, validate structure.
     *
     * @throws InvalidArgumentException if URL is malformed or fails validation
     */
    public function normalize(string $url, bool $requireHttps = false): string
    {
        try {
            $uri = new Uri($url);

            $uri = UriNormalizer::normalize(
                $uri,
                UriNormalizer::REMOVE_DEFAULT_PORT | UriNormalizer::REMOVE_DUPLICATE_SLASHES
            );

            $scheme = $uri->getScheme();
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException(
                    sprintf('URL must use HTTP or HTTPS protocol. Got: "%s"', $scheme)
                );
            }

            $host = $uri->getHost();
            if (empty($host)) {
                throw new InvalidArgumentException('URL must include a valid domain name');
            }

            if ($requireHttps && $scheme !== 'https') {
                throw new InvalidArgumentException(
                    sprintf('URL must use HTTPS for security. Got: "%s"', $url)
                );
            }

            return rtrim((string) $uri, '/');
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid URL format: "%s". Error: %s', $url, $e->getMessage())
            );
        }
    }
}
