<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Http;

use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testRetryableAnswersAreRepeatedUntilTheLastAttempt(): void
    {
        $policy = new RetryPolicy(3, 1.0, 30.0);
        $get = new Request('GET', 'https://x.test/');
        $busy = new ApiException('busy', 503, 'http_503');

        self::assertSame(1.0, $policy->delayBeforeRetry($busy, $get, 1));
        self::assertSame(2.0, $policy->delayBeforeRetry($busy, $get, 2));
        self::assertNull($policy->delayBeforeRetry($busy, $get, 3));
    }

    public function testRetryAfterIsHonouredUpToTheMaximum(): void
    {
        $policy = new RetryPolicy(3, 1.0, 30.0);
        $get = new Request('GET', 'https://x.test/');

        self::assertSame(7.0, $policy->delayBeforeRetry(new ApiException('slow down', 429, 'http_429', 7), $get, 1));
        self::assertNull($policy->delayBeforeRetry(new ApiException('slow down', 429, 'http_429', 600), $get, 1));
    }

    public function testOtherErrorsAreNotRepeated(): void
    {
        self::assertNull((new RetryPolicy())->delayBeforeRetry(new ApiException('bad', 422, 'http_422'), new Request('GET', 'https://x.test/'), 1));
    }

    public function testATransportFailureIsRepeatedOnlyWhenIdempotent(): void
    {
        $policy = new RetryPolicy();
        $failure = new TransportException('timeout');

        self::assertNotNull($policy->delayBeforeRetry($failure, new Request('PUT', 'https://x.test/'), 1));
        self::assertNull($policy->delayBeforeRetry($failure, new Request('POST', 'https://x.test/'), 1));
        self::assertNotNull($policy->delayBeforeRetry($failure, new Request('POST', 'https://x.test/', [], null, 30.0, true), 1));
    }

    public function testNoneNeverRepeats(): void
    {
        self::assertNull(RetryPolicy::none()->delayBeforeRetry(new ApiException('busy', 503, 'http_503'), new Request('GET', 'https://x.test/'), 1));
    }
}
