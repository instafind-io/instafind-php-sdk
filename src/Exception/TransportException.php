<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

/**
 * No answer arrived: DNS failure, connection refused, TLS error, timeout,
 * or an answer broke off while its body was read.
 *
 * The request may or may not have reached Instafind. The SDK repeats it only
 * when it is idempotent.
 */
class TransportException extends InstafindException
{
    public function __construct(string $message, private readonly string $url = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The URL that could not be reached, '' when unknown. */
    public function url(): string
    {
        return $this->url;
    }

    /** Always true: a request without an answer may succeed when repeated. */
    public function isRetryable(): bool
    {
        return true;
    }
}
