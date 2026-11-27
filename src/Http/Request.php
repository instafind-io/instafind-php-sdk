<?php

declare(strict_types=1);

namespace Instafind\Sdk\Http;

/**
 * One HTTP request, as a {@see \Instafind\Sdk\Transport\Transport} sends it.
 *
 * Immutable. Header names are kept as given; transports send them as they are.
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param float                 $timeout seconds for the whole request
     * @param bool                  $idempotent whether sending it twice has the same effect as once,
     *                                          which decides if a request that got no answer may be repeated
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly array $headers = [],
        private readonly ?string $body = null,
        private readonly float $timeout = 30.0,
        private readonly ?bool $idempotent = null,
    ) {}

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /** A header's value by case-insensitive name, null when absent. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    /**
     * Whether the request may be repeated after it got no answer. Defaults by
     * method: everything but POST and PATCH.
     */
    public function isIdempotent(): bool
    {
        return $this->idempotent ?? !in_array($this->method(), ['POST', 'PATCH'], true);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = [];
        foreach ($this->headers as $key => $existing) {
            if (strcasecmp($key, $name) !== 0) {
                $headers[$key] = $existing;
            }
        }
        $headers[$name] = $value;

        return new self($this->method, $this->url, $headers, $this->body, $this->timeout, $this->idempotent);
    }

    public function withTimeout(float $timeout): self
    {
        return new self($this->method, $this->url, $this->headers, $this->body, $timeout, $this->idempotent);
    }
}
