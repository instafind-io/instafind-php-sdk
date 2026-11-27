<?php

declare(strict_types=1);

namespace Instafind\Sdk\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;

/**
 * The HTTP layer under the SDK. Implement it to send requests through
 * something the SDK does not ship an adapter for.
 *
 * A transport sends what it is given and returns what came back, whatever the
 * status: interpreting 4xx and 5xx answers is the SDK's job. It never follows
 * redirects.
 */
interface Transport
{
    /**
     * @throws TransportException when no response arrived (DNS, refused, TLS, timeout)
     */
    public function send(Request $request): Response;

    /**
     * Several requests at once, in parallel where the adapter can. Keys are
     * preserved; one failing request does not fail the others.
     *
     * @template K of array-key
     *
     * @param array<K, Request> $requests
     *
     * @return array<K, Response|TransportException>
     */
    public function sendMany(array $requests): array;
}
