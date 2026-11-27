<?php

declare(strict_types=1);

namespace Instafind\Sdk\Http;

use Instafind\Sdk\Sdk;

/**
 * The User-Agent every SDK request sends:
 *
 *     instafind-php-sdk/1.0.0 (instafind-for-woocommerce/2.2.0; https://shop.example.com/)
 *
 * The part in brackets names the integration and the site; Instafind shows it
 * on the API key's activity. A bare SDK caller may leave it out.
 */
final class UserAgent
{
    /**
     * @param string $integration        the integration's product token, e.g. `instafind-for-woocommerce`
     * @param string $integrationVersion its version
     * @param string $siteUrl            the shop's address
     */
    public function __construct(
        private readonly string $integration = '',
        private readonly string $integrationVersion = '',
        private readonly string $siteUrl = '',
    ) {
        foreach (['integration' => $integration, 'integration version' => $integrationVersion] as $what => $value) {
            if (preg_match('#[\s;()/]#', $value) === 1) {
                throw new \InvalidArgumentException("The $what must not contain spaces, slashes, semicolons or brackets: '$value'.");
            }
        }
        if (preg_match('#[\s;()]#', $siteUrl) === 1) {
            throw new \InvalidArgumentException("The site URL must not contain spaces, semicolons or brackets: '$siteUrl'.");
        }
    }

    public function toString(): string
    {
        $product = $this->integration === '' ? '' : $this->integration . '/' . ($this->integrationVersion === '' ? 'unknown' : $this->integrationVersion);
        $comment = implode('; ', array_filter([$product, $this->siteUrl], static fn(string $part): bool => $part !== ''));

        return Sdk::NAME . '/' . Sdk::VERSION . ($comment === '' ? '' : ' (' . $comment . ')');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
