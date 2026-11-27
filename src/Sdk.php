<?php

declare(strict_types=1);

namespace Instafind\Sdk;

/**
 * Facts about this SDK build.
 */
final class Sdk
{
    /**
     * The SDK's version, sent in every User-Agent. `scripts/release.sh` sets it
     * in the release commit; CI refuses a tag that differs from it.
     */
    public const VERSION = '1.0.0-dev';

    /** The product token of the User-Agent. */
    public const NAME = 'instafind-php-sdk';

    private function __construct() {}
}
