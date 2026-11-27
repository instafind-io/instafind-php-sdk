<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Http;

use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Sdk;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    public function testTheFullForm(): void
    {
        self::assertSame(
            'instafind-php-sdk/' . Sdk::VERSION . ' (instafind-for-woocommerce/2.2.0; https://shop.example.com/)',
            (new UserAgent('instafind-for-woocommerce', '2.2.0', 'https://shop.example.com/'))->toString(),
        );
    }

    public function testABareCaller(): void
    {
        self::assertSame('instafind-php-sdk/' . Sdk::VERSION, (string) new UserAgent());
    }

    public function testOnlyTheSite(): void
    {
        self::assertSame('instafind-php-sdk/' . Sdk::VERSION . ' (https://a.example/)', (new UserAgent('', '', 'https://a.example/'))->toString());
    }

    public function testRefusesWhatWouldBreakTheFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserAgent('my plugin', '1.0');
    }

    public function testTheVersionConstantIsSemVer(): void
    {
        self::assertMatchesRegularExpression('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-dev)?$/', Sdk::VERSION);
    }
}
