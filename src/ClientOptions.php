<?php

declare(strict_types=1);

namespace Instafind\Sdk;

use Instafind\Sdk\Http\RetryPolicy;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Support\Sleeper;
use Instafind\Sdk\Support\SystemSleeper;
use Instafind\Sdk\Transport\CurlTransport;
use Instafind\Sdk\Transport\Transport;

/**
 * How an API client talks to Instafind. Everything is optional; what is left
 * out gets the client's default ({@see PublicApi}, {@see IntegrationApi}).
 *
 *     $options = new ClientOptions(
 *         userAgent: new UserAgent('instafind-for-woocommerce', '2.2.0', home_url('/')),
 *         transport: new WordPressTransport(),
 *     );
 */
final class ClientOptions
{
    /**
     * @param Transport|null   $transport   default: a new {@see CurlTransport}
     * @param UserAgent|null   $userAgent   default: the bare SDK User-Agent
     * @param RetryPolicy|null $retryPolicy default: the client's own
     * @param float|null       $timeout     seconds per request; default: the client's own
     * @param Sleeper|null     $sleeper     how to wait between attempts
     */
    public function __construct(
        private readonly ?Transport $transport = null,
        private readonly ?UserAgent $userAgent = null,
        private readonly ?RetryPolicy $retryPolicy = null,
        private readonly ?float $timeout = null,
        private readonly ?Sleeper $sleeper = null,
    ) {
        if ($timeout !== null && $timeout <= 0) {
            throw new \InvalidArgumentException('The timeout must be more than 0 seconds.');
        }
    }

    public function withTransport(Transport $transport): self
    {
        return new self($transport, $this->userAgent, $this->retryPolicy, $this->timeout, $this->sleeper);
    }

    public function withUserAgent(UserAgent $userAgent): self
    {
        return new self($this->transport, $userAgent, $this->retryPolicy, $this->timeout, $this->sleeper);
    }

    public function withRetryPolicy(RetryPolicy $retryPolicy): self
    {
        return new self($this->transport, $this->userAgent, $retryPolicy, $this->timeout, $this->sleeper);
    }

    public function withTimeout(float $timeout): self
    {
        return new self($this->transport, $this->userAgent, $this->retryPolicy, $timeout, $this->sleeper);
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        return new self($this->transport, $this->userAgent, $this->retryPolicy, $this->timeout, $sleeper);
    }

    public function transport(): Transport
    {
        return $this->transport ?? new CurlTransport();
    }

    public function userAgent(): UserAgent
    {
        return $this->userAgent ?? new UserAgent();
    }

    /** The retry policy, or `$default` when none was given. */
    public function retryPolicy(RetryPolicy $default): RetryPolicy
    {
        return $this->retryPolicy ?? $default;
    }

    /** The timeout, or `$default` when none was given. */
    public function timeout(float $default): float
    {
        return $this->timeout ?? $default;
    }

    public function sleeper(): Sleeper
    {
        return $this->sleeper ?? new SystemSleeper();
    }
}
