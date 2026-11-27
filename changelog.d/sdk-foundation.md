### Added

- Transports: `CurlTransport` (the default, parallel requests with curl_multi), `Psr18Transport` for any PSR-18 client, `WordPressTransport` over the WordPress HTTP API, and `Testing\MockTransport` for tests.
- Credentials: `StaticKey` for site API keys, and "Connect to Instafind" with OAuth 2.1 and PKCE: `OAuthClient` (discovery, authorize URL, code exchange, refresh, revoke) and `OAuthConnection`, which renews the access token behind the lock of a host-provided `TokenStore`.
- Errors as exceptions under `InstafindException`: `ApiException` (status, error code, retryable, Retry-After), `IndexNotFoundException`, `TransportException`, `InvalidSchemaException`.
- `RetryPolicy`: temporary answers (429, 503, the "wait and retry" 409s) and unanswered idempotent requests are repeated.
- Every request sends `User-Agent: instafind-php-sdk/<version> (<integration>/<version>; <site>)` from `UserAgent` and `Sdk::VERSION`.
