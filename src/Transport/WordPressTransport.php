<?php

declare(strict_types=1);

namespace Instafind\Sdk\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;

/**
 * {@see Transport} over the WordPress HTTP API: `wp_remote_request()` for one
 * request, the bundled Requests library for parallel ones. Proxies, CA
 * bundle and the `http_request_args` filters apply as for any WordPress
 * request.
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
        if (!class_exists(\WpOrg\Requests\Requests::class) || count($requests) < 2) {
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
