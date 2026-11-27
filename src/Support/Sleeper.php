<?php

declare(strict_types=1);

namespace Instafind\Sdk\Support;

/**
 * How the SDK waits between attempts. Replace it in tests, or in a host that
 * must not block.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
