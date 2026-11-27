<?php

declare(strict_types=1);

namespace Instafind\Sdk\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;

/**
 * The default {@see Transport}: ext-curl, with curl_multi for parallel requests.
 */
final class CurlTransport implements Transport
{
    /**
     * Response headers per open handle, filled while the response arrives.
     *
     * @var array<int, \ArrayObject<string, string>>
     */
    private array $headers = [];

    /**
     * @param float             $connectTimeout seconds to establish the connection, capped at each request's timeout
     * @param array<int, mixed> $curlOptions    extra CURLOPT_* options (a proxy, a CA bundle …), applied last
     * @param int               $maxParallel    requests {@see self::sendMany()} keeps open at once
     */
    public function __construct(
        private readonly float $connectTimeout = 5.0,
        private readonly array $curlOptions = [],
        private readonly int $maxParallel = 10,
    ) {
        if (!extension_loaded('curl')) {
            throw new \LogicException('CurlTransport needs the curl extension. Install ext-curl or pass another Transport.');
        }
    }

    public function send(Request $request): Response
    {
        $handle = $this->handle($request);
        $body = curl_exec($handle);
        if ($body === false || curl_errno($handle) !== 0) {
            $error = curl_error($handle);
            unset($this->headers[spl_object_id($handle)]);
            throw new TransportException('Instafind could not be reached: ' . ($error !== '' ? $error : 'unknown cURL error'), $request->url());
        }

        return $this->response($handle, (string) $body);
    }

    public function sendMany(array $requests): array
    {
        $out = [];
        $pending = $requests;
        $multi = curl_multi_init();
        /** @var array<int, array{0: array-key, 1: \CurlHandle, 2: Request}> $active */
        $active = [];

        try {
            while ($pending !== [] || $active !== []) {
                while ($pending !== [] && count($active) < max(1, $this->maxParallel)) {
                    $key = array_key_first($pending);
                    $request = $pending[$key];
                    unset($pending[$key]);
                    $handle = $this->handle($request);
                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = [$key, $handle, $request];
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'] ?? null;
                    $result = $info['result'] ?? null;
                    if (!$handle instanceof \CurlHandle || !is_int($result) || !isset($active[spl_object_id($handle)])) {
                        continue;
                    }
                    $id = spl_object_id($handle);
                    [$key, , $request] = $active[$id];
                    unset($active[$id]);

                    if ($result !== CURLE_OK) {
                        $error = curl_error($handle);
                        $out[$key] = new TransportException('Instafind could not be reached: ' . ($error !== '' ? $error : (string) curl_strerror($result)), $request->url());
                        unset($this->headers[$id]);
                    } else {
                        $out[$key] = $this->response($handle, (string) curl_multi_getcontent($handle));
                    }
                    curl_multi_remove_handle($multi, $handle);
                }

                if ($active !== [] && $running > 0) {
                    curl_multi_select($multi, 0.1);
                }
            }
        } finally {
            foreach ($active as $id => [, $handle]) {
                curl_multi_remove_handle($multi, $handle);
                unset($this->headers[$id]);
            }
            curl_multi_close($multi);
        }

        // Answer in the order asked.
        $ordered = [];
        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $out[$key];
        }

        return $ordered;
    }

    private function handle(Request $request): \CurlHandle
    {
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        // cURL would otherwise add `Expect: 100-continue` to larger bodies and wait for it.
        $headers[] = 'Expect:';

        $handle = curl_init();
        $timeoutMs = (int) round($request->timeout() * 1000);
        $options = [
            CURLOPT_URL => $request->url(),
            CURLOPT_CUSTOMREQUEST => $request->method(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, (int) round($this->connectTimeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($request->method() === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($request->body() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body();
        }

        /** @var \ArrayObject<string, string> $headersOut */
        $headersOut = new \ArrayObject();
        $options[CURLOPT_HEADERFUNCTION] = static function (\CurlHandle $handle, string $line) use ($headersOut): int {
            if (str_starts_with($line, 'HTTP/')) {
                // A new status line (after a 100 Continue): start over.
                $headersOut->exchangeArray([]);
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headersOut[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        };

        /** @var array<int, mixed> $options */
        curl_setopt_array($handle, $options);
        if ($this->curlOptions !== []) {
            curl_setopt_array($handle, $this->curlOptions);
        }
        $this->headers[spl_object_id($handle)] = $headersOut;

        return $handle;
    }

    private function response(\CurlHandle $handle, string $body): Response
    {
        $id = spl_object_id($handle);
        $headers = isset($this->headers[$id]) ? $this->headers[$id]->getArrayCopy() : [];
        unset($this->headers[$id]);

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
