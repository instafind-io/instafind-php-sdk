<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit;

use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Exception\InvalidSchemaException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Integration\RefreshMode;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;
use Instafind\Sdk\Testing\MockTransport;
use Instafind\Sdk\Tests\Fixtures\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class IntegrationApiTest extends TestCase
{
    private const INDEX = 'b5c1c9c8-6f0e-4c3a-9a51-0d3c1c7a0001';

    private const WEBSITE = 'a1a1a1a1-6f0e-4c3a-9a51-0d3c1c7a0002';

    private const BASE = 'https://platform.example/api/integration/v1';

    private MockTransport $transport;

    private IntegrationApi $api;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->api = new IntegrationApi('https://platform.example', new StaticKey('ifk_secret'), new ClientOptions(transport: $this->transport, sleeper: new RecordingSleeper()));
    }

    public function testKey(): void
    {
        $this->transport->queueJson(200, ['data' => [
            'id' => 'k1', 'kind' => 'connection', 'name' => 'Shop', 'prefix' => null,
            'client' => ['client_id' => 'woo', 'name' => 'Instafind for WooCommerce'], 'connected_url' => 'https://shop.example/',
            'website' => ['id' => self::WEBSITE, 'name' => 'Shop'], 'scopes' => ['indexes:read', 'products:write'],
            'all_indexes' => false, 'search_index_ids' => [self::INDEX], 'expires_at' => null, 'created_at' => '2026-11-01T10:00:00+00:00',
        ]]);

        $key = $this->api->key();

        self::assertSame(self::BASE . '/key', $this->transport->lastRequest()->url());
        self::assertSame('Bearer ifk_secret', $this->transport->lastRequest()->header('Authorization'));
        self::assertSame(IntegrationApi::DEFAULT_TIMEOUT, $this->transport->lastRequest()->timeout());
        self::assertSame('connection', $key->kind());
        self::assertSame('woo', $key->clientId());
        self::assertSame(self::WEBSITE, $key->websiteId());
        self::assertTrue($key->hasScope('products:write'));
        self::assertFalse($key->hasScope('orders:write'));
        self::assertTrue($key->coversIndex(self::INDEX));
        self::assertFalse($key->coversIndex('other'));
        self::assertNull($key->expiresAt());
        self::assertSame('2026-11-01', $key->createdAt()?->format('Y-m-d'));
    }

    public function testCreateIndexIsNeverRepeated(): void
    {
        $this->transport->queueJson(503, ['error' => 'index_creation_failed'], ['retry-after' => '30']);
        $schema = IndexSchema::fromFields(['id' => FieldType::Identifier, 'title' => FieldType::Title]);

        try {
            $this->api->createIndex(self::WEBSITE, $schema, 'el', 'Shop EL');
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame('index_creation_failed', $e->errorCode());
        }
        self::assertCount(1, $this->transport->requests());
        $request = $this->transport->lastRequest();
        self::assertSame(self::BASE . '/websites/' . self::WEBSITE . '/search-indices', $request->url());
        self::assertSame('{"langcode":"el","fields":[{"field_id":"id","type":"identifier"},{"field_id":"title","type":"title"}],"name":"Shop EL"}', $request->body());
    }

    public function testCreateIndexChecksTheLangcodeFirst(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->api->createIndex(self::WEBSITE, IndexSchema::fromFields(['id' => 'identifier']), 'fr');
    }

    public function testIndex(): void
    {
        $this->transport->queueJson(200, ['data' => [
            'id' => self::INDEX, 'name' => 'Shop', 'source' => 'api', 'lifecycle_state' => 'active', 'status' => 1, 'rebuild' => null, 'langcode' => 'el',
            'field_definitions' => [['field_id' => 'w', 'type' => 'number', 'is_custom' => true, 'rebuild_pending' => false, 'sample_values' => ['1', 2]]],
            'created_at' => '2026-11-01T10:00:00Z',
        ]]);

        $index = $this->api->index(self::INDEX);

        self::assertTrue($index->isActive());
        self::assertTrue($index->isEnabled());
        self::assertNull($index->rebuild());
        self::assertSame(FieldType::Number, $index->fieldDefinitions()[0]->fieldType());
        self::assertSame(['1', '2'], $index->fieldDefinitions()[0]->sampleValues());
    }

    public function testAPlainNotFoundOnAnIndexRouteMeansTheIndex(): void
    {
        $this->transport->queueJson(404, ['error' => 'not_found', 'message' => 'Not found.']);

        try {
            $this->api->index(self::INDEX);
            self::fail('No exception');
        } catch (IndexNotFoundException $e) {
            self::assertSame('index_not_found', $e->errorCode());
        }
    }

    public function testAPlainNotFoundElsewhereStaysAnApiException(): void
    {
        $this->transport->queueJson(404, ['error' => 'not_found']);

        try {
            $this->api->website(self::WEBSITE);
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(IndexNotFoundException::class, $e);
        }
    }

    public function testAMissingIndexOnFieldAndTreeRoutesMeansTheIndex(): void
    {
        $calls = [
            fn() => $this->api->updateField(self::INDEX, 'colour', ['type' => 'attribute']),
            fn() => $this->api->putFilterValueTree(self::INDEX, 'category', []),
        ];
        foreach ($calls as $call) {
            $this->transport->queueJson(404, ['error' => 'not_found', 'message' => 'Not found.']);
            try {
                $call();
                self::fail('No exception');
            } catch (IndexNotFoundException $e) {
                self::assertSame('index_not_found', $e->errorCode());
            }
        }
    }

    public function testAnUnknownFieldStaysAnApiException(): void
    {
        $this->transport->queueJson(404, ['message' => "Field 'colour' not found."]);

        try {
            $this->api->updateField(self::INDEX, 'colour', ['type' => 'attribute']);
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(IndexNotFoundException::class, $e);
            self::assertSame('http_404', $e->errorCode());
        }
    }

    public function testBehaviorsOfAnIndexWithoutFieldsOrWithNumericFieldIds(): void
    {
        $this->transport->queue(new Response(200, ['content-type' => 'application/json'], '{"data":{},"meta":{"searchable_fields":[]}}'));
        $this->transport->queue(new Response(200, ['content-type' => 'application/json'], '{"data":[],"meta":{"searchable_fields":[]}}'));
        $this->transport->queue(new Response(200, ['content-type' => 'application/json'], '{"message":"ok","data":{"data":{},"meta":{}}}'));
        $this->transport->queue(new Response(200, ['content-type' => 'application/json'], '{"data":{"0":{"searchable":true},"1":{"facet":true}},"meta":{}}'));

        $empty = [
            $this->api->searchBehaviors(self::INDEX)->all(),
            $this->api->searchBehaviors(self::INDEX)->all(),
            $this->api->putSearchBehaviors(self::INDEX, [])->all(),
        ];
        self::assertSame([[], [], []], $empty);
        $numeric = $this->api->searchBehaviors(self::INDEX);
        self::assertCount(2, $numeric->all());
        self::assertSame(['facet' => true], $numeric->forField('1'));
    }

    public function testACampaignPageCannotBeCleared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api->setCampaignPage(self::INDEX, ' ');
    }

    public function testFieldsAndBehaviors(): void
    {
        $this->transport->queueJson(201, ['message' => 'Custom field created successfully', 'data' => ['field_id' => 'colour', 'type' => 'color']]);
        $this->transport->queueJson(200, ['message' => 'ok', 'data' => ['field_id' => 'colour', 'type' => 'attribute', 'rebuild_pending' => true]]);
        $this->transport->queueJson(200, ['data' => ['title' => ['searchable' => true]], 'meta' => ['searchable_fields' => ['title'], 'facetable_fields' => []]]);
        $this->transport->queueJson(200, ['message' => 'ok', 'data' => ['data' => ['title' => ['searchable' => false]], 'meta' => ['searchable_fields' => []]]]);
        $this->transport->queueJson(200, ['data' => ['field_id' => 'category', 'nodes' => 2]]);

        self::assertSame('color', $this->api->addField(self::INDEX, 'colour', FieldType::Color)->type());
        self::assertSame('{"field_id":"colour","type":"color"}', $this->transport->lastRequest()->body());
        self::assertTrue($this->api->updateField(self::INDEX, 'colour', ['type' => 'attribute'])->isRebuildPending());
        self::assertSame(self::BASE . '/search-indices/' . self::INDEX . '/field-definitions/colour', $this->transport->lastRequest()->url());
        $behaviors = $this->api->searchBehaviors(self::INDEX);
        self::assertSame(['title'], $behaviors->searchableFields());
        self::assertSame(['searchable' => true], $behaviors->forField('title'));
        self::assertSame([], $this->api->putSearchBehaviors(self::INDEX, ['title' => ['searchable' => false]])->searchableFields());
        self::assertSame('{"behaviors":{"title":{"searchable":false}}}', $this->transport->lastRequest()->body());
        self::assertSame(2, $this->api->putFilterValueTree(self::INDEX, 'category', [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B', 'parent' => 'a']]));
    }

    public function testAddFieldChecksTheIdFirst(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->api->addField(self::INDEX, 'Bad Id', FieldType::Attribute);
    }

    public function testShopSetup(): void
    {
        $this->transport->queueJson(200, ['data' => ['campaign_page_url' => 'https://shop.example/c/{name}']]);
        $this->transport->queueJson(200, ['data' => ['applied' => false, 'reason' => 'no_products', 'sidebar' => [], 'modal' => []]]);
        $this->transport->queueJson(200, ['data' => ['results' => 'applied', 'live_search' => 'not_sent']]);

        $this->api->setCampaignPage(self::INDEX, 'https://shop.example/c/{name}');
        self::assertSame('PUT', $this->transport->lastRequest()->method());
        self::assertSame('{"campaign_page_url":"https://shop.example/c/{name}"}', $this->transport->lastRequest()->body());

        $filters = $this->api->setUpDefaultFilters(self::INDEX, [['field_id' => 'brand', 'label' => 'Brand']]);
        self::assertFalse($filters->isApplied());
        self::assertSame('no_products', $filters->reason());

        $template = $this->api->setUpDefaultTemplate(self::INDEX, '<div>{{title}}</div>');
        self::assertSame('applied', $template->results());
        self::assertSame('not_sent', $template->liveSearch());
        self::assertSame('{"results":"<div>{{title}}</div>"}', $this->transport->lastRequest()->body());
    }

    public function testStoredProducts(): void
    {
        $this->transport->queueJson(200, ['data' => [['product_uid' => 'p1', 'title' => 'One', 'raw_data' => ['id' => 'p1'], 'processed_data' => null, 'filtered' => true, 'pending' => false, 'updated_at' => '2026-11-27T12:00:00Z']], 'meta' => ['current_page' => 2, 'per_page' => 1, 'total' => 9, 'total_pages' => 9]]);
        $this->transport->queueJson(404, ['error' => 'product_not_found']);
        $this->transport->queueJson(200, ['data' => [], 'meta' => ['total' => 1234]]);

        $page = $this->api->storedProducts(self::INDEX, 2, 1, 'one');
        self::assertSame(self::BASE . '/search-indices/' . self::INDEX . '/stored-products?page=2&per_page=1&search=one', $this->transport->lastRequest()->url());
        self::assertTrue($page->hasMore());
        self::assertSame(9, $page->total());
        $product = $page->products()[0];
        self::assertSame('p1', $product->productId());
        self::assertTrue($product->isFiltered());
        self::assertSame(['id' => 'p1'], $product->rawData());
        self::assertNull($product->processedData());
        self::assertSame('12:00', $product->updatedAt()?->format('H:i'));

        self::assertNull($this->api->storedProduct(self::INDEX, 'a/b'));
        self::assertSame(self::BASE . '/search-indices/' . self::INDEX . '/stored-products/a%2Fb', $this->transport->lastRequest()->url());

        self::assertSame(1234, $this->api->countProducts(self::INDEX));
        self::assertStringEndsWith('?page=1&per_page=1', $this->transport->lastRequest()->url());
    }

    public function testReports(): void
    {
        $this->transport->queueJson(200, ['search_index_id' => self::INDEX, 'window' => ['days' => 14, 'start_at' => '2026-11-13T00:00:00Z', 'end_at' => '2026-11-27T00:00:00Z'], 'summary' => ['searches' => 10], 'top_popular_queries' => [['query' => 'shoes']]]);
        $this->transport->queueJson(200, ['search_index_id' => self::INDEX, 'merchandising_rules' => ['active' => 2, 'total' => 3], 'campaigns' => ['live' => 1, 'total' => 4], 'content_snippets' => ['active' => 0, 'total' => 1], 'synonyms' => 7, 'hidden_searches' => 0]);

        $analytics = $this->api->analytics(self::INDEX, 14);
        self::assertStringEndsWith('/analytics?days=14', $this->transport->lastRequest()->url());
        self::assertSame(14, $analytics->days());
        self::assertSame('2026-11-13', $analytics->startAt()?->format('Y-m-d'));
        self::assertSame(['searches' => 10], $analytics->summary());
        self::assertSame([['query' => 'shoes']], $analytics->section('top_popular_queries'));

        $summary = $this->api->summary(self::INDEX);
        self::assertSame(2, $summary->activeMerchandisingRules());
        self::assertSame(4, $summary->campaigns());
        self::assertSame(7, $summary->synonyms());
    }

    public function testOrders(): void
    {
        $this->transport->queueFailure();
        $this->transport->queueJson(200, ['accepted' => 1, 'stale' => 0, 'failed' => 1, 'results' => [['reference' => '1001', 'status' => 'ok'], ['reference' => '1002', 'status' => 'error', 'reason' => 'currency']]]);
        $this->transport->queueJson(200, ['forgotten_orders' => 3]);

        $result = $this->api->sendOrders(self::WEBSITE, [['reference' => '1001'], ['reference' => '1002']]);
        self::assertCount(2, $this->transport->requests(), 'orders are keyed by reference, so a lost answer is repeated');
        self::assertFalse($result->isClean());
        self::assertSame('1002', $result->errors()[0]->reference());
        self::assertSame('currency', $result->errors()[0]->reason());

        $key = str_repeat('a', 64);
        self::assertSame(3, $this->api->forgetCustomer(self::WEBSITE, $key));
        self::assertSame('DELETE', $this->transport->lastRequest()->method());
        self::assertSame(self::BASE . '/websites/' . self::WEBSITE . '/orders/customers/' . $key, $this->transport->lastRequest()->url());
    }

    public function testProducts(): void
    {
        $this->transport->queueJson(200, ['successful' => 1, 'filtered' => 0, 'pending' => 0, 'failed' => 1, 'results' => [['id' => '1', 'status' => 'ok'], ['id' => '2', 'status' => 'error', 'reason' => 'unknown_field: colour']], 'meta' => ['duration_ms' => 12, 'es_duration_ms' => 5, 'refresh_policy' => 'async']]);
        $this->transport->queueJson(200, ['successful' => 1, 'filtered' => 0, 'pending' => 0, 'failed' => 0, 'results' => [['id' => 'x/1', 'status' => 'ok']], 'meta' => []]);
        $this->transport->queue(new Response(204));
        $this->transport->queueJson(404, ['error' => 'product_not_found']);

        $write = $this->api->upsertProducts(self::INDEX, [['id' => '1'], ['id' => '2', 'colour' => 'red']], RefreshMode::WaitFor);
        self::assertSame(self::BASE . '/search-indices/' . self::INDEX . '/products?refresh=wait_for', $this->transport->lastRequest()->url());
        self::assertSame(1, $write->failed());
        self::assertFalse($write->isClean());
        self::assertSame('unknown_field: colour', $write->errors()[0]->reason());
        self::assertSame('async', $write->refreshPolicy());

        self::assertTrue($this->api->upsertProduct(self::INDEX, 'x/1', ['id' => 'x/1'])->isClean());
        self::assertSame(self::BASE . '/search-indices/' . self::INDEX . '/products/x%2F1', $this->transport->lastRequest()->url());

        $this->api->deleteProduct(self::INDEX, 'x/1');
        $this->api->deleteProduct(self::INDEX, 'gone');
        self::assertSame('DELETE', $this->transport->lastRequest()->method());
    }

    public function testAProductWriteIsRepeatedAfterALostAnswer(): void
    {
        $this->transport->queue(new TransportException('timeout'));
        $this->transport->queueJson(200, ['successful' => 1, 'failed' => 0, 'results' => []]);

        self::assertSame(1, $this->api->upsertProducts(self::INDEX, [['id' => '1']])->successful());
        self::assertCount(2, $this->transport->requests());
    }

    public function testBatchLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api->upsertProducts(self::INDEX, array_fill(0, 501, ['id' => 'x']));
    }
}
