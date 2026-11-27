<?php

declare(strict_types=1);

namespace Instafind\Sdk\Http;

use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Exception\TransportException;

/**
 * When the SDK repeats a request by itself, and how long it waits first.
 *
 * Repeated: answers the API marks as temporary ({@see ApiException::isRetryable()}:
 * 429, 503, the "wait and retry" 409s), and requests that got no answer at
 * all, but only when they are idempotent: a POST that may have arrived is
 * not sent twice.
 */
final class RetryPolicy
{
    /**
     * @param int   $maxAttempts  attempts in total, the first included: 3 means up to two repeats
     * @param float $defaultDelay seconds to wait when the answer has no Retry-After
     * @param float $maxDelay     the longest wait, so a large Retry-After cannot stall a run;
     *                            an answer asking for longer is not repeated but thrown
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly float $defaultDelay = 1.0,
        private readonly float $maxDelay = 30.0,
    ) {}

    /** Never repeat: for storefront requests that must answer fast. */
    public static function none(): self
    {
        return new self(1);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Seconds to wait before attempt `$attempt + 1`, or null to give up and
     * throw `$failure`.
     */
    public function delayBeforeRetry(InstafindException $failure, Request $request, int $attempt): ?float
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }
        if ($failure instanceof TransportException) {
            return $request->isIdempotent() ? $this->backoff($attempt) : null;
        }
        if (!$failure instanceof ApiException || !$failure->isRetryable()) {
            return null;
        }
        $retryAfter = $failure->retryAfter();
        if ($retryAfter === null) {
            return $this->backoff($attempt);
        }

        return $retryAfter > $this->maxDelay ? null : (float) $retryAfter;
    }

    /** 1×, 2×, 4× … the default delay, never more than the maximum. */
    private function backoff(int $attempt): float
    {
        return min($this->maxDelay, $this->defaultDelay * (2 ** ($attempt - 1)));
    }
}
