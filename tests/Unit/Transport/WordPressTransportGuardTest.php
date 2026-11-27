<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Transport;

use Instafind\Sdk\Transport\WordPressTransport;
use PHPUnit\Framework\TestCase;

final class WordPressTransportGuardTest extends TestCase
{
    public function testRefusesToRunOutsideWordPress(): void
    {
        if (function_exists('wp_remote_request')) {
            self::markTestSkipped('WordPress functions are loaded in this process.');
        }

        $this->expectException(\LogicException::class);
        new WordPressTransport();
    }
}
