<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Fixtures;

use Instafind\Sdk\Support\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ?FakeClock $clock = null) {}

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock?->advance((int) ceil($seconds));
    }
}
