<?php

declare(strict_types=1);

// A stand-in for the Requests library WordPress bundles, loaded in a separate process.

namespace WpOrg\Requests\Response {
    final class Headers
    {
        /** @param array<string, list<string>> $headers */
        public function __construct(private array $headers) {}

        /** @return array<string, list<string>> */
        public function getAll(): array
        {
            return $this->headers;
        }
    }
}

namespace WpOrg\Requests {
    final class Response
    {
        public int $status_code = 200;

        public string $body = '';

        public Response\Headers $headers;

        public function __construct()
        {
            $this->headers = new Response\Headers([]);
        }
    }

    final class Exception extends \Exception {}

    final class Requests
    {
        /** @var array{0: array<array-key, array<string, mixed>>, 1: array<string, mixed>}|null */
        public static ?array $last = null;

        /**
         * @param array<array-key, array<string, mixed>> $requests
         * @param array<string, mixed>                   $options
         * @return array<array-key, Response|Exception>
         */
        public static function request_multiple(array $requests, array $options = []): array
        {
            self::$last = [$requests, $options];
            $out = [];
            foreach ($requests as $key => $request) {
                if (str_contains((string) $request['url'], 'down')) {
                    $out[$key] = new Exception('cURL error 7: refused');
                    continue;
                }
                $response = new Response();
                $response->status_code = 200;
                $response->body = $request['type'] . ' ' . $request['url'];
                $response->headers = new Response\Headers(['X-Seen' => ['1', '2']]);
                $out[$key] = $response;
            }

            return $out;
        }
    }
}
