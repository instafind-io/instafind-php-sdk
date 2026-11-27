<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Auth;

use Instafind\Sdk\Auth\OAuth\AuthorizationServer;
use Instafind\Sdk\Auth\OAuth\OAuthClient;
use Instafind\Sdk\Auth\OAuth\Pkce;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Testing\MockTransport;
use Instafind\Sdk\Tests\Fixtures\FakeClock;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
    private const META = [
        'issuer' => 'https://platform.example',
        'authorization_endpoint' => 'https://platform.example/oauth/authorize',
        'token_endpoint' => 'https://platform.example/oauth/token',
        'revocation_endpoint' => 'https://platform.example/oauth/revoke',
        'code_challenge_methods_supported' => ['S256'],
    ];

    public function testDiscoverReadsTheMetadata(): void
    {
        $transport = (new MockTransport())->queueJson(200, self::META);

        $server = OAuthClient::discover('https://platform.example/', $transport);

        self::assertSame('https://platform.example/.well-known/oauth-authorization-server', $transport->lastRequest()->url());
        self::assertSame('https://platform.example/oauth/token', $server->tokenEndpoint());
        self::assertEquals($server, AuthorizationServer::fromArray($server->toArray()));
    }

    public function testDiscoverRefusesPlainHttpEndpointsForAnHttpsAddress(): void
    {
        $transport = (new MockTransport())->queueJson(200, ['token_endpoint' => 'http://platform.example/oauth/token'] + self::META);

        $this->expectException(ApiException::class);
        OAuthClient::discover('https://platform.example', $transport);
    }

    public function testDiscoverRefusesAServerWithoutS256(): void
    {
        $transport = (new MockTransport())->queueJson(200, ['code_challenge_methods_supported' => ['plain']] + self::META);

        try {
            OAuthClient::discover('https://platform.example', $transport);
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame('oauth_unavailable', $e->errorCode());
        }
    }

    public function testAuthorizeBuildsTheUrlWithPkce(): void
    {
        $oauth = new OAuthClient(self::server(), 'client-1', new MockTransport());

        $request = $oauth->authorize('https://shop.example/callback', ['indexes:manage', 'products:write']);

        $parts = parse_url($request->url());
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-1', $query['client_id']);
        self::assertSame('indexes:manage products:write', $query['scope']);
        self::assertSame($request->state(), $query['state']);
        self::assertSame(Pkce::challenge($request->verifier()), $query['code_challenge']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(43, strlen($request->verifier()));
        self::assertTrue($request->matchesState($request->state()));
        self::assertFalse($request->matchesState('other'));
    }

    public function testPkceChallengeMatchesTheRfcExample(): void
    {
        // RFC 7636, appendix B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testExchangeCodeSendsTheVerifierAndReadsTheTokens(): void
    {
        $transport = (new MockTransport())->queueJson(200, [
            'access_token' => 'ifa_a',
            'refresh_token' => 'ifr_r',
            'expires_in' => 3600,
            'scope' => 'indexes:manage products:write',
            'website_id' => 'w-1',
        ]);
        $clock = new FakeClock();
        $oauth = new OAuthClient(self::server(), 'client-1', $transport, 15.0, null, $clock);
        $request = $oauth->authorize('https://shop.example/callback', []);

        $tokens = $oauth->exchangeCode('the-code', $request);

        self::assertSame('ifa_a', $tokens->accessToken());
        self::assertSame('ifr_r', $tokens->refreshToken());
        self::assertSame($clock->now + 3600, $tokens->expiresAtTimestamp());
        self::assertSame(['indexes:manage', 'products:write'], $tokens->scopes());
        self::assertSame('w-1', $tokens->websiteId());

        $sent = $transport->lastRequest();
        self::assertSame('POST', $sent->method());
        self::assertSame('https://platform.example/oauth/token', $sent->url());
        self::assertFalse($sent->isIdempotent());
        parse_str((string) $sent->body(), $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('the-code', $form['code']);
        self::assertSame($request->verifier(), $form['code_verifier']);
        self::assertSame('https://shop.example/callback', $form['redirect_uri']);
    }

    public function testAShortLifetimeIsStretchedToTwoMinutes(): void
    {
        $transport = (new MockTransport())->queueJson(200, ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 5]);
        $clock = new FakeClock();

        $tokens = (new OAuthClient(self::server(), 'c', $transport, 15.0, null, $clock))->refresh('r0');

        self::assertSame($clock->now + 120, $tokens->expiresAtTimestamp());
    }

    public function testTokenErrorsCarryTheRfcCode(): void
    {
        $transport = (new MockTransport())->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'Spent.']);

        try {
            (new OAuthClient(self::server(), 'c', $transport))->refresh('r0');
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame('invalid_grant', $e->errorCode());
            self::assertSame('Spent.', $e->getMessage());
        }
    }

    public function testATokenResponseWithoutTokensIsUnexpected(): void
    {
        $transport = (new MockTransport())->queueJson(200, ['access_token' => 'ifa_secret']);

        try {
            (new OAuthClient(self::server(), 'c', $transport))->refresh('r0');
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode());
            // The body may hold a token: it stays out of the exception.
            self::assertSame('', $e->body());
        }
    }

    public function testRevoke(): void
    {
        $transport = (new MockTransport())->queueJson(200, []);

        (new OAuthClient(self::server(), 'c', $transport))->revoke('ifr_r');

        parse_str((string) $transport->lastRequest()->body(), $form);
        self::assertSame(['token' => 'ifr_r', 'client_id' => 'c'], $form);
        self::assertSame('https://platform.example/oauth/revoke', $transport->lastRequest()->url());
    }

    public static function server(): AuthorizationServer
    {
        return new AuthorizationServer(self::META['issuer'], self::META['authorization_endpoint'], self::META['token_endpoint'], self::META['revocation_endpoint']);
    }
}
