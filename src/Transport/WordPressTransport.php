<?php

declare(strict_types=1);

namespace Instafind\Sdk\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;

/**
 * {@see Transport} over the WordPress HTTP API: `wp_remote_request()` for one
 * request, the bundled Requests library for parallel ones.
 *
 * Single requests get everything a WordPress request gets: proxy, CA bundle,
 * `WP_HTTP_BLOCK_EXTERNAL` and the `pre_http_request` / `http_request_args`
 * filters. Parallel requests get WordPress's CA bundle but skip those
 * filters; on a site with a proxy or blocked external requests,
 * {@see self::sendMany()} therefore sends one request after the other through
 * `wp_remote_request()` instead.
 *
 * Each request's timeout is the SDK's (8 s for an OAuth refresh). A filter
 * that raises it, or a site without ext-curl (whose fallback times each read,
 * not the whole request), can let a refresh outlast the 35-second renewal
 * lock of {@see \Instafind\Sdk\Auth\OAuth\OAuthConnection}.
 */
final class WordPressTransport implements Transport
{
    public function __construct()
    {
        if (!function_exists('wp_remote_request')) {
            throw new \LogicException('WordPressTransport works only inside WordPress.');
        }
    }

    public function send(Request $request): Response
    {
        $args = [
            'method' => $request->method(),
            'headers' => $this->headersWithoutUserAgent($request),
            'timeout' => $request->timeout(),
            'redirection' => 0,
        ];
        $userAgent = $request->header('User-Agent');
        if ($userAgent !== null) {
            $args['user-agent'] = $userAgent;
        }
        if ($request->body() !== null) {
            $args['body'] = $request->body();
        }

        $response = wp_remote_request($request->url(), $args);
        if (is_wp_error($response)) {
            throw new TransportException('Instafind could not be reached: ' . $response->get_error_message(), $request->url());
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = self::lastValue($value);
        }

        return new Response((int) wp_remote_retrieve_response_code($response), $headers, (string) wp_remote_retrieve_body($response));
    }

    public function sendMany(array $requests): array
    {
        if (!class_exists(\WpOrg\Requests\Requests::class) || count($requests) < 2 || self::wordPressRoutesRequests()) {
            $out = [];
            foreach ($requests as $key => $request) {
                try {
                    $out[$key] = $this->send($request);
                } catch (TransportException $e) {
                    $out[$key] = $e;
                }
            }

            return $out;
        }

        $multi = [];
        $timeout = 0.0;
        foreach ($requests as $key => $request) {
            $options = [
                'timeout' => $request->timeout(),
                'connect_timeout' => $request->timeout(),
                'follow_redirects' => false,
            ];
            $userAgent = $request->header('User-Agent');
            if ($userAgent !== null) {
                $options['useragent'] = $userAgent;
            }
            $multi[$key] = [
                'url' => $request->url(),
                'headers' => $this->headersWithoutUserAgent($request),
                'data' => $request->body() ?? [],
                'type' => $request->method(),
                'options' => $options,
            ];
            if ($request->body() !== null) {
                // Requests would put a DELETE's data in the query string, and fail on a string.
                $multi[$key]['options']['data_format'] = 'body';
            }
            $timeout = max($timeout, $request->timeout());
        }

        $shared = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'follow_redirects' => false,
        ];
        $abspath = defined('ABSPATH') ? constant('ABSPATH') : null;
        $wpinc = defined('WPINC') ? constant('WPINC') : null;
        if (is_string($abspath) && is_string($wpinc)) {
            // The CA bundle WordPress itself uses.
            $shared['verify'] = $abspath . $wpinc . '/certificates/ca-bundle.crt';
        }

        $responses = \WpOrg\Requests\Requests::request_multiple($multi, $shared);

        $out = [];
        foreach ($requests as $key => $request) {
            $response = $responses[$key] ?? null;
            if (!$response instanceof \WpOrg\Requests\Response) {
                $message = $response instanceof \Throwable ? $response->getMessage() : 'request failed';
                $out[$key] = new TransportException('Instafind could not be reached: ' . $message, $request->url());
                continue;
            }
            $headers = [];
            foreach ($response->headers->getAll() as $name => $values) {
                $headers[strtolower((string) $name)] = self::lastValue($values);
            }
            $out[$key] = new Response((int) $response->status_code, $headers, (string) $response->body);
        }

        return $out;
    }

    /**
     * Whether WordPress sends requests through a proxy or blocks external
     * ones, which parallel requests through Requests would bypass.
     */
    private static function wordPressRoutesRequests(): bool
    {
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && constant('WP_HTTP_BLOCK_EXTERNAL')) {
            return true;
        }

        return class_exists(\WP_HTTP_Proxy::class) && (new \WP_HTTP_Proxy())->is_enabled();
    }

    /** A header value WordPress may give as a list: its last entry. */
    private static function lastValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * WordPress sets the User-Agent from its own argument and would send a
     * header of that name twice.
     *
     * @return array<string, string>
     */
    private function headersWithoutUserAgent(Request $request): array
    {
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            if (strcasecmp($name, 'User-Agent') !== 0) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
