<?php

declare(strict_types=1);

namespace Instafind\Sdk\Http;

use Instafind\Sdk\Auth\Credential;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Support\Query;
use Instafind\Sdk\Support\Sleeper;
use Instafind\Sdk\Support\SystemSleeper;
use Instafind\Sdk\Transport\Transport;

/**
 * The plumbing every API class shares: URLs, headers, JSON, error mapping,
 * retries and the renewal of an expired access token.
 *
 * @internal
 */
final class ApiClient
{
    private readonly string $baseUrl;

    private readonly Sleeper $sleeper;

    public function __construct(
        string $baseUrl,
        private readonly Transport $transport,
        private readonly UserAgent $userAgent,
        private readonly RetryPolicy $retryPolicy,
        private readonly float $timeout,
        private readonly ?Credential $credential = null,
        ?Sleeper $sleeper = null,
    ) {
        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#^https?://[^/?\#]+#i', $baseUrl) !== 1) {
            throw new \InvalidArgumentException("The Instafind address must start with https:// (or http:// for development), got '$baseUrl'.");
        }
        $this->baseUrl = $baseUrl;
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * The absolute URL of a path with its query.
     *
     * @param array<string, mixed> $query
     */
    public function url(string $path, array $query = []): string
    {
        $queryString = Query::build($query);

        return $this->baseUrl . $path . ($queryString === '' ? '' : (str_contains($path, '?') ? '&' : '?') . $queryString);
    }

    /**
     * A request with the SDK's headers, a JSON body if given, and no credential yet.
     *
     * @param array<string, mixed>         $query
     * @param array<array-key, mixed>|null $json
     * @param array<string, string>|null   $form a form body instead of JSON
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, ?float $timeout = null, ?bool $idempotent = null, ?array $form = null): Request
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent->toString(),
        ];
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = self::encode($json);
        } elseif ($form !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }

        return new Request($method, $this->url($path, $query), $headers, $body, $timeout ?? $this->timeout, $idempotent);
    }

    /**
     * Send with the credential (if any), repeating as the retry policy says
     * and once more after renewing an expired access token.
     *
     * @return array<array-key, mixed> the decoded JSON body ([] for an empty one)
     *
     * @throws InstafindException
     */
    public function send(Request $request): array
    {
        $renewed = false;
        for ($attempt = 1; ; $attempt++) {
            try {
                return self::decode($this->transport->send($this->authorize($request)));
            } catch (ApiException $e) {
                if (!$renewed && $e->errorCode() === 'access_token_expired' && $this->credential !== null && $this->credential->renew()) {
                    $renewed = true;
                    $attempt--;
                    continue;
                }
                $delay = $this->retryPolicy->delayBeforeRetry($e, $request, $attempt);
                if ($delay === null) {
                    throw $e;
                }
            } catch (TransportException $e) {
                $delay = $this->retryPolicy->delayBeforeRetry($e, $request, $attempt);
                if ($delay === null) {
                    throw $e;
                }
            }
            $this->sleeper->sleep($delay);
        }
    }

    /**
     * Send several requests in parallel, once each: no retries, so a slow
     * answer cannot hold up the others.
     *
     * @template K of array-key
     *
     * @param array<K, Request> $requests
     *
     * @return array<K, array<array-key, mixed>|InstafindException>
     */
    public function sendMany(array $requests): array
    {
        $authorized = array_map(fn(Request $r): Request => $this->authorize($r), $requests);
        $out = [];
        foreach ($this->transport->sendMany($authorized) as $key => $response) {
            if ($response instanceof TransportException) {
                $out[$key] = $response;
                continue;
            }
            try {
                $out[$key] = self::decode($response);
            } catch (ApiException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    /**
     * The JSON body of a successful response, or the exception for an error.
     *
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    public static function decode(Response $response): array
    {
        if ($response->status() >= 400) {
            throw ApiException::fromResponse($response);
        }
        if ($response->status() < 200 || $response->status() >= 300) {
            throw ApiException::unexpected('Instafind answered HTTP ' . $response->status() . ', which the SDK does not expect.', $response);
        }
        if (trim($response->body()) === '') {
            return [];
        }
        $decoded = json_decode($response->body(), true);
        if (!is_array($decoded)) {
            throw ApiException::unexpected('Instafind sent a response that is not a JSON object.', $response);
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $json
     */
    public static function encode(array $json): string
    {
        try {
            return json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The request body cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private function authorize(Request $request): Request
    {
        if ($this->credential === null) {
            return $request;
        }
        $token = $this->credential->bearer();

        return $token === '' ? $request : $request->withHeader('Authorization', 'Bearer ' . $token);
    }
}
