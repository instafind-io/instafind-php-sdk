<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Indexing;

use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Indexing\BulkListener;
use Instafind\Sdk\Indexing\BulkResult;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\Testing\MockTransport;
use Instafind\Sdk\Tests\Fixtures\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class BulkLoaderTest extends TestCase
{
    private MockTransport $transport;

    private IntegrationApi $api;

    protected function setUp(): void
    {
        // Answers every batch: all ok, except products whose id starts with "bad".
        $this->transport = new MockTransport(static function (Request $request): Response {
            /** @var array{products: list<array{id: string}>} $body */
            $body = json_decode((string) $request->body(), true);
            $results = array_map(static fn(array $p): array => str_starts_with($p['id'], 'bad')
                ? ['id' => $p['id'], 'status' => 'error', 'reason' => 'unknown_field: x']
                : ['id' => $p['id'], 'status' => 'ok'], $body['products']);
            $failed = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'error'));

            return new Response(200, [], (string) json_encode(['successful' => count($results) - $failed, 'filtered' => 0, 'pending' => 0, 'failed' => $failed, 'results' => $results]));
        });
        $this->api = new IntegrationApi('https://platform.example', new StaticKey('ifk_x'), new ClientOptions(transport: $this->transport, sleeper: new RecordingSleeper()));
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function products(int $count, string $prefix = 'p'): \Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield ['id' => $prefix . $i];
        }
    }

    public function testBatchesAndWaitsOnlyForTheLast(): void
    {
        $result = $this->api->bulkLoader(2)->load('idx', self::products(5));

        self::assertSame(5, $result->successful());
        self::assertSame(5, $result->sent());
        self::assertSame(3, $result->batches());
        self::assertTrue($result->isClean());
        $urls = array_map(static fn(Request $r): string => $r->url(), $this->transport->requests());
        self::assertStringEndsNotWith('refresh=wait_for', $urls[0]);
        self::assertStringEndsNotWith('refresh=wait_for', $urls[1]);
        self::assertStringEndsWith('/products?refresh=wait_for', $urls[2]);
    }

    public function testAnExactMultipleStillKnowsItsLastBatch(): void
    {
        $this->api->bulkLoader(2)->load('idx', self::products(4));

        self::assertCount(2, $this->transport->requests());
        self::assertStringEndsWith('refresh=wait_for', $this->transport->lastRequest()->url());
    }

    public function testWithoutWaiting(): void
    {
        $this->api->bulkLoader(10)->withoutWaitingForRefresh()->load('idx', self::products(3));

        self::assertStringEndsWith('/products', $this->transport->lastRequest()->url());
    }

    public function testRejectionsInsideA200AreCounted(): void
    {
        $result = $this->api->bulkLoader()->load('idx', [['id' => 'p1'], [], ['id' => 'bad1']]);

        self::assertSame(1, $result->successful());
        self::assertSame(1, $result->failed());
        self::assertSame(2, $result->sent());
        self::assertSame('bad1', $result->errors()[0]->id());
        self::assertFalse($result->isClean());
    }

    public function testTheBatchSizeIsCapped(): void
    {
        self::assertSame(500, $this->api->bulkLoader(1000)->batchSize());
        self::assertSame(1, $this->api->bulkLoader(0)->batchSize());
    }

    public function testWithoutAListenerARefusedBatchIsThrown(): void
    {
        $transport = (new MockTransport())->queueJson(422, ['error' => 'duplicate_product_ids', 'message' => 'Duplicate ids.']);
        $api = new IntegrationApi('https://platform.example', new StaticKey('ifk_x'), new ClientOptions(transport: $transport));

        $this->expectException(ApiException::class);
        $api->bulkLoader()->load('idx', self::products(2));
    }

    public function testAListenerCanCarryOn(): void
    {
        $transport = (new MockTransport())
            ->queueJson(422, ['error' => 'duplicate_product_ids', 'message' => 'Duplicate ids.'])
            ->queueJson(200, ['successful' => 1, 'failed' => 0, 'results' => [['id' => 'p3', 'status' => 'ok']]]);
        $api = new IntegrationApi('https://platform.example', new StaticKey('ifk_x'), new ClientOptions(transport: $transport));
        $listener = new class implements BulkListener {
            /** @var list<int> */
            public array $loaded = [];

            /** @var list<string> */
            public array $failed = [];

            public function batchLoaded(int $documents, BulkResult $running): void
            {
                $this->loaded[] = $documents;
            }

            public function batchFailed(int $documents, InstafindException $failure): bool
            {
                $this->failed[] = $failure->getMessage();

                return true;
            }
        };

        $result = $api->bulkLoader(2)->load('idx', self::products(3), $listener);

        self::assertSame(1, $result->successful());
        self::assertSame(2, $result->failed());
        self::assertCount(1, $result->failures());
        self::assertSame([2, 1], $listener->loaded);
        self::assertSame(['Duplicate ids.'], $listener->failed);
    }

    public function testPreviewSendsNothing(): void
    {
        $result = $this->api->bulkLoader(2)->preview(self::products(3));

        self::assertSame(3, $result->sent());
        self::assertSame(2, $result->batches());
        self::assertSame([], $this->transport->requests());
    }
}
