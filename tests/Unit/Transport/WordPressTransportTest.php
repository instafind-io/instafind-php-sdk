<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Transport\WordPressTransport;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class WordPressTransportTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../Fixtures/wordpress.php';
    }

    public function testPassesTheRequestToWordPress(): void
    {
        $GLOBALS['wp_responses'][] = [
            'headers' => ['Retry-After' => '3', 'Set-Cookie' => ['a', 'b']],
            'response' => ['code' => 429],
            'body' => '{"message":"slow down"}',
        ];

        $response = (new WordPressTransport())->send(new Request('POST', 'https://platform.example/x', [
            'Content-Type' => 'application/json',
            'User-Agent' => 'instafind-php-sdk/1.0.0 (plugin/2.0; https://shop/)',
        ], '{"a":1}', 12.5));

        self::assertSame(429, $response->status());
        self::assertSame('3', $response->header('retry-after'));
        self::assertSame('b', $response->header('set-cookie'));
        self::assertSame('{"message":"slow down"}', $response->body());

        [$url, $args] = $GLOBALS['wp_requests'][0];
        self::assertSame('https://platform.example/x', $url);
        self::assertSame('POST', $args['method']);
        self::assertSame(['Content-Type' => 'application/json'], $args['headers']);
        self::assertSame('instafind-php-sdk/1.0.0 (plugin/2.0; https://shop/)', $args['user-agent']);
        self::assertSame(12.5, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertSame('{"a":1}', $args['body']);
    }

    public function testAWpErrorIsATransportException(): void
    {
        $GLOBALS['wp_responses'][] = new \WP_Error('http_request_failed', 'cURL error 28: timed out');

        try {
            (new WordPressTransport())->send(new Request('GET', 'https://platform.example/x'));
            self::fail('No exception');
        } catch (TransportException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertSame('https://platform.example/x', $e->url());
        }
    }

    public function testWithoutRequestsSendManyGoesOneByOne(): void
    {
        $GLOBALS['wp_responses'][] = ['headers' => [], 'response' => ['code' => 200], 'body' => 'one'];
        $GLOBALS['wp_responses'][] = new \WP_Error('x', 'down');

        $out = (new WordPressTransport())->sendMany(['a' => new Request('GET', 'https://p/a'), 'b' => new Request('GET', 'https://p/b')]);

        self::assertInstanceOf(Response::class, $out['a']);
        self::assertSame('one', $out['a']->body());
        self::assertInstanceOf(TransportException::class, $out['b']);
    }

    public function testWithRequestsSendManyRunsInParallel(): void
    {
        require_once __DIR__ . '/../../Fixtures/wordpress-requests.php';

        $out = (new WordPressTransport())->sendMany([
            'a' => new Request('GET', 'https://p/a', ['User-Agent' => 'ua', 'Accept' => 'application/json'], null, 3.0),
            'b' => new Request('GET', 'https://p/down', [], null, 4.0),
        ]);

        self::assertInstanceOf(Response::class, $out['a']);
        self::assertSame('GET https://p/a', $out['a']->body());
        self::assertSame('2', $out['a']->header('x-seen'));
        self::assertInstanceOf(TransportException::class, $out['b']);
        self::assertStringContainsString('refused', $out['b']->getMessage());

        self::assertNotNull(\WpOrg\Requests\Requests::$last);
        [$requests, $options] = \WpOrg\Requests\Requests::$last;
        self::assertSame(['Accept' => 'application/json'], $requests['a']['headers']);
        self::assertSame('ua', $requests['a']['options']['useragent']);
        self::assertSame(4.0, $options['timeout']);
        self::assertFalse($options['follow_redirects']);
        self::assertSame([], $GLOBALS['wp_requests']);
    }

    public function testParallelRequestsSendTheirBodyAsTheBody(): void
    {
        require_once __DIR__ . '/../../Fixtures/wordpress-requests.php';

        (new WordPressTransport())->sendMany([
            'a' => new Request('DELETE', 'https://p/a', [], '{"ids":[1]}'),
            'b' => new Request('GET', 'https://p/b'),
        ]);

        self::assertNotNull(\WpOrg\Requests\Requests::$last);
        [$requests] = \WpOrg\Requests\Requests::$last;
        self::assertSame('body', $requests['a']['options']['data_format']);
        self::assertArrayNotHasKey('data_format', $requests['b']['options']);
    }

    public function testWithAProxySendManyGoesThroughWordPress(): void
    {
        require_once __DIR__ . '/../../Fixtures/wordpress-requests.php';
        define('WP_PROXY_HOST', 'proxy.example');
        eval('final class WP_HTTP_Proxy { public function is_enabled(): bool { return defined("WP_PROXY_HOST"); } }');
        $GLOBALS['wp_responses'][] = ['headers' => [], 'response' => ['code' => 200], 'body' => 'one'];
        $GLOBALS['wp_responses'][] = ['headers' => [], 'response' => ['code' => 200], 'body' => 'two'];

        $out = (new WordPressTransport())->sendMany(['a' => new Request('GET', 'https://p/a'), 'b' => new Request('GET', 'https://p/b')]);

        self::assertInstanceOf(Response::class, $out['b']);
        self::assertSame('two', $out['b']->body());
        self::assertCount(2, $GLOBALS['wp_requests']);
        self::assertNull(\WpOrg\Requests\Requests::$last);
    }

    public function testWithExternalRequestsBlockedSendManyGoesThroughWordPress(): void
    {
        require_once __DIR__ . '/../../Fixtures/wordpress-requests.php';
        define('WP_HTTP_BLOCK_EXTERNAL', true);
        $GLOBALS['wp_responses'][] = ['headers' => [], 'response' => ['code' => 200], 'body' => 'one'];
        $GLOBALS['wp_responses'][] = ['headers' => [], 'response' => ['code' => 200], 'body' => 'two'];

        (new WordPressTransport())->sendMany(['a' => new Request('GET', 'https://p/a'), 'b' => new Request('GET', 'https://p/b')]);

        self::assertCount(2, $GLOBALS['wp_requests']);
        self::assertNull(\WpOrg\Requests\Requests::$last);
    }
}
