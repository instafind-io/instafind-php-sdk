<?php

declare(strict_types=1);

namespace Instafind\Sdk\Support;

/**
 * The current time as a Unix timestamp. Replace it in tests.
 */
interface Clock
{
    public function now(): int;
}
