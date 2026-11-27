<?php

declare(strict_types=1);

// Just enough of WordPress for WordPressTransportTest, loaded in a separate process.

namespace {
    final class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    /** @var list<array{string, array<string, mixed>}> */
    $GLOBALS['wp_requests'] = [];

    /** @var list<array<string, mixed>|WP_Error> */
    $GLOBALS['wp_responses'] = [];

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    function wp_remote_request(string $url, array $args = []): array|WP_Error
    {
        $GLOBALS['wp_requests'][] = [$url, $args];

        return array_shift($GLOBALS['wp_responses']) ?? new WP_Error('none', 'no response queued');
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    /** @param array<string, mixed> $response */
    function wp_remote_retrieve_headers(array $response): array
    {
        return $response['headers'];
    }

    /** @param array<string, mixed> $response */
    function wp_remote_retrieve_response_code(array $response): int
    {
        return $response['response']['code'];
    }

    /** @param array<string, mixed> $response */
    function wp_remote_retrieve_body(array $response): string
    {
        return $response['body'];
    }
}
