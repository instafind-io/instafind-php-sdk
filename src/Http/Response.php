<?php

declare(strict_types=1);

namespace Instafind\Sdk\Http;

/**
 * One HTTP response, as a {@see \Instafind\Sdk\Transport\Transport} received it.
 *
 * Header names are lower-cased; a header sent more than once keeps its last value.
 */
final class Response
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $body = '',
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }
        $this->headers = $lower;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string> lower-cased names
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }
}
