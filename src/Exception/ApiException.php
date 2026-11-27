<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

use Instafind\Sdk\Http\Response;

/**
 * Instafind answered with an error status, or with a body that is not the
 * JSON the SDK expected.
 *
 * {@see self::errorCode()} is the API's machine-readable `error` key when it
 * sent one (`index_locked`, `access_token_expired`, `invalid_grant` …),
 * otherwise `http_<status>`, or `unexpected_response` for a 2xx body the SDK
 * could not read.
 */
class ApiException extends InstafindException
{
    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    /** Error codes the API documents as "wait and retry", whatever the status. */
    private const RETRYABLE_CODES = ['index_not_ready', 'index_locked', 'write_conflict', 'rebuild_in_progress'];

    /** The longest Retry-After {@see self::retryDelay()} honours, in seconds. */
    private const MAX_DELAY = 300;

    /** The wait {@see self::retryDelay()} suggests without a Retry-After. */
    private const DEFAULT_DELAY = 30;

    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly ?int $retryAfter = null,
        private readonly string $body = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Build the exception for an error response: the subclass that fits, the
     * API's message and code, and its Retry-After.
     *
     * @internal
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $decoded = json_decode($response->body(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        $code = is_string($decoded['error'] ?? null) && $decoded['error'] !== '' ? $decoded['error'] : 'http_' . $status;

        $message = 'HTTP ' . $status;
        if (is_string($decoded['message'] ?? null) && $decoded['message'] !== '') {
            $message = $decoded['message'];
        } elseif (is_string($decoded['error_description'] ?? null) && $decoded['error_description'] !== '') {
            // OAuth endpoints answer in the RFC 6749 shape.
            $message = $decoded['error_description'];
        } elseif (is_array($decoded['results'] ?? null) && is_string($decoded['results']['error'] ?? null)) {
            // Search errors (a failed search, an index not serving) say why in `results.error`.
            $message = 'HTTP ' . $status . ': ' . $decoded['results']['error'];
        }
        // Validation errors: the first field's first message is the useful one.
        if ($status === 422 && is_array($decoded['errors'] ?? null)) {
            $first = reset($decoded['errors']);
            if (is_array($first) && is_string($first[0] ?? null)) {
                $message = $first[0];
            }
        }

        $retryAfter = self::parseRetryAfter($response->header('retry-after'));

        // Only this code names the index; another 404 (a wrong base URL, an
        // unknown product) is not about the index.
        if ($status === 404 && $code === 'index_not_found') {
            return new IndexNotFoundException($message, $status, $code, $retryAfter, $response->body());
        }

        return new self($message, $status, $code, $retryAfter, $response->body());
    }

    /**
     * A 2xx answer the SDK could not read.
     *
     * @internal
     */
    public static function unexpected(string $message, Response $response): self
    {
        return new self($message, $response->status(), self::UNEXPECTED_RESPONSE, null, $response->body());
    }

    /** The HTTP status. */
    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** The server's Retry-After in seconds, null when it sent none. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** The response body as it arrived, for logs and operators. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * The response body decoded, null when it is not JSON.
     *
     * @return array<string, mixed>|null
     */
    public function decodedBody(): ?array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Whether repeating the identical request can succeed: every 429 and 503
     * ("nothing changed, retry"), and the 409s the API marks as temporary.
     * 404 and 422 never are.
     */
    public function isRetryable(): bool
    {
        if ($this->status === 429 || $this->status === 503) {
            return true;
        }

        return in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }

    /**
     * Seconds to wait before repeating: the server's Retry-After (at most five
     * minutes), else 30.
     */
    public function retryDelay(): int
    {
        if ($this->retryAfter !== null && $this->retryAfter > 0) {
            return min($this->retryAfter, self::MAX_DELAY);
        }

        return self::DEFAULT_DELAY;
    }

    /**
     * Retry-After as seconds from now. RFC 9110 allows delay-seconds or an
     * HTTP-date; Instafind has sent both.
     *
     * @internal
     */
    public static function parseRetryAfter(?string $header): ?int
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/', $header) === 1) {
            return (int) $header;
        }
        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
