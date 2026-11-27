<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Exception;

use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Http\Response;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    public function testReadsTheErrorCodeMessageAndRetryAfter(): void
    {
        $e = ApiException::fromResponse(new Response(409, ['Retry-After' => '12'], '{"error":"index_locked","message":"The index is locked."}'));

        self::assertSame(409, $e->status());
        self::assertSame('index_locked', $e->errorCode());
        self::assertSame('The index is locked.', $e->getMessage());
        self::assertSame(12, $e->retryAfter());
        self::assertTrue($e->isRetryable());
        self::assertSame(12, $e->retryDelay());
    }

    public function testWithoutAnErrorKeyTheCodeIsTheStatus(): void
    {
        $e = ApiException::fromResponse(new Response(500, [], '<html>oops</html>'));

        self::assertSame('http_500', $e->errorCode());
        self::assertSame('HTTP 500', $e->getMessage());
        self::assertFalse($e->isRetryable());
        self::assertNull($e->decodedBody());
        self::assertSame('<html>oops</html>', $e->body());
        self::assertSame(30, $e->retryDelay());
    }

    public function testValidationErrorsGiveTheFirstMessage(): void
    {
        $e = ApiException::fromResponse(new Response(422, [], '{"message":"The given data was invalid.","errors":{"products.0.id":["The id is required."]}}'));

        self::assertSame('The id is required.', $e->getMessage());
        self::assertFalse($e->isRetryable());
    }

    public function testSearchErrorsSayWhyInResults(): void
    {
        $e = ApiException::fromResponse(new Response(400, [], '{"results":{"error":"Missing id parameter"}}'));

        self::assertSame('HTTP 400: Missing id parameter', $e->getMessage());
    }

    public function testOAuthErrorsUseTheDescription(): void
    {
        $e = ApiException::fromResponse(new Response(400, [], '{"error":"invalid_grant","error_description":"The refresh token is invalid."}'));

        self::assertSame('invalid_grant', $e->errorCode());
        self::assertSame('The refresh token is invalid.', $e->getMessage());
    }

    public function testAMissingIndexHasItsOwnClass(): void
    {
        self::assertInstanceOf(IndexNotFoundException::class, ApiException::fromResponse(new Response(404, [], '{"error":"index_not_found"}')));
        self::assertNotInstanceOf(IndexNotFoundException::class, ApiException::fromResponse(new Response(404, [], '{"error":"product_not_found"}')));
    }

    public function testEvery503And429IsRetryable(): void
    {
        self::assertTrue(ApiException::fromResponse(new Response(503))->isRetryable());
        self::assertTrue(ApiException::fromResponse(new Response(429))->isRetryable());
    }

    public function testRetryAfterAsADate(): void
    {
        $seconds = ApiException::parseRetryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() + 20));

        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(18, $seconds);
        self::assertLessThanOrEqual(20, $seconds);
        self::assertNull(ApiException::parseRetryAfter('soon'));
        self::assertSame(300, (new ApiException('x', 503, 'http_503', 9999))->retryDelay());
    }
}
