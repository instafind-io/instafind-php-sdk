<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Support\Clock;
use Instafind\Sdk\Support\Query;
use Instafind\Sdk\Support\SystemClock;
use Instafind\Sdk\Transport\Transport;

/**
 * "Connect to Instafind": OAuth 2.1 authorization code with PKCE (S256) and
 * rotating refresh tokens, for a public client (no secret: one shipped in a
 * plugin would be no secret).
 *
 * Errors of the token and revocation endpoints are {@see ApiException}s whose
 * error code is the RFC 6749 one (`invalid_grant` …).
 */
final class OAuthClient
{
    /** The metadata document (RFC 8414), relative to the Instafind address. */
    public const METADATA_PATH = '/.well-known/oauth-authorization-server';

    private readonly Clock $clock;

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly string $clientId,
        private readonly Transport $transport,
        private readonly float $timeout = 15.0,
        private readonly ?UserAgent $userAgent = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Read Instafind's endpoints from its metadata. An https address accepts
     * only https endpoints; plain http is left to local development.
     *
     * @throws ApiException       `oauth_unavailable` when the address offers no usable OAuth server
     * @throws TransportException
     */
    public static function discover(string $baseUrl, Transport $transport, float $timeout = 15.0, ?UserAgent $userAgent = null): AuthorizationServer
    {
        $response = $transport->send(new Request('GET', rtrim($baseUrl, '/') . self::METADATA_PATH, self::headers($userAgent), null, $timeout));
        $meta = json_decode($response->body(), true);
        if ($response->status() !== 200 || !is_array($meta)) {
            throw new ApiException('This Instafind address does not offer "Connect to Instafind".', $response->status(), 'oauth_unavailable', null, $response->body());
        }

        $scheme = str_starts_with(strtolower($baseUrl), 'https://') ? 'https' : 'https?';
        $urls = [];
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint'] as $key) {
            $url = $meta[$key] ?? null;
            if (!is_string($url) || preg_match('#^' . $scheme . '://[^/?\#]+#i', $url) !== 1) {
                throw new ApiException("Instafind's OAuth metadata has no usable $key.", 200, 'oauth_unavailable', null, $response->body());
            }
            $urls[$key] = $url;
        }
        if (!in_array('S256', (array) ($meta['code_challenge_methods_supported'] ?? []), true)) {
            throw new ApiException("Instafind's OAuth server does not offer PKCE with S256.", 200, 'oauth_unavailable', null, $response->body());
        }

        return new AuthorizationServer($urls['issuer'], $urls['authorization_endpoint'], $urls['token_endpoint'], $urls['revocation_endpoint']);
    }

    public function server(): AuthorizationServer
    {
        return $this->server;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * Start a connection: a fresh state and PKCE verifier, and the address to
     * send the browser to. Keep the returned object until the redirect comes back.
     *
     * @param list<string> $scopes
     */
    public function authorize(string $redirectUri, array $scopes): AuthorizationRequest
    {
        $state = Pkce::newState();
        $verifier = Pkce::newVerifier();
        $endpoint = $this->server->authorizationEndpoint();
        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . Query::build([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return new AuthorizationRequest($url, $state, $verifier, $redirectUri);
    }

    /**
     * Trade the code from the redirect for tokens.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function exchangeCode(string $code, AuthorizationRequest $request): Tokens
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $request->redirectUri(),
            'code_verifier' => $request->verifier(),
            'client_id' => $this->clientId,
        ]);
    }

    /**
     * New access and refresh tokens. The refresh token passed in is spent:
     * presenting it again outside a short retry window makes Instafind revoke
     * the whole connection. {@see OAuthConnection} handles that.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function refresh(string $refreshToken): Tokens
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
        ]);
    }

    /**
     * End the connection (RFC 7009). Either token revokes all of it.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function revoke(string $token): void
    {
        $response = $this->postForm($this->server->revocationEndpoint(), [
            'token' => $token,
            'client_id' => $this->clientId,
        ]);
        if ($response->status() !== 200) {
            throw ApiException::fromResponse($response);
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function tokenRequest(array $fields): Tokens
    {
        $response = $this->postForm($this->server->tokenEndpoint(), $fields);
        if ($response->status() !== 200) {
            throw ApiException::fromResponse($response);
        }
        $tokens = json_decode($response->body(), true);
        $access = is_array($tokens) ? ($tokens['access_token'] ?? null) : null;
        $refresh = is_array($tokens) ? ($tokens['refresh_token'] ?? null) : null;
        if (!is_array($tokens) || !is_string($access) || !is_string($refresh)) {
            // Without the body: it may hold a token, and bodies end up in logs.
            throw new ApiException('Instafind sent a token response without tokens.', $response->status(), ApiException::UNEXPECTED_RESPONSE);
        }
        $expiresIn = $tokens['expires_in'] ?? null;
        $scope = $tokens['scope'] ?? null;
        $websiteId = $tokens['website_id'] ?? null;
        /** @var array<string, mixed> $tokens */

        return new Tokens(
            $access,
            $refresh,
            // At least two minutes, so renewing a minute early cannot become renewing on every call.
            $this->clock->now() + max(120, is_numeric($expiresIn) ? (int) $expiresIn : 3600),
            array_values(array_filter(explode(' ', is_string($scope) ? $scope : ''), static fn(string $s): bool => $s !== '')),
            is_string($websiteId) ? $websiteId : '',
            $tokens,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function postForm(string $url, array $fields): Response
    {
        $headers = self::headers($this->userAgent) + ['Content-Type' => 'application/x-www-form-urlencoded'];

        // Not idempotent: a refresh token may be spent even when the answer is lost.
        return $this->transport->send(new Request('POST', $url, $headers, http_build_query($fields, '', '&', PHP_QUERY_RFC3986), $this->timeout, false));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(?UserAgent $userAgent): array
    {
        return ['Accept' => 'application/json', 'User-Agent' => ($userAgent ?? new UserAgent())->toString()];
    }
}
