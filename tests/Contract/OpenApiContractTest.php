<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Contract;

use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Integration\RefreshMode;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\PublicApi;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;
use Instafind\Sdk\Search\CampaignEvent;
use Instafind\Sdk\Search\FacetSort;
use Instafind\Sdk\Search\ProductSuggestionsQuery;
use Instafind\Sdk\Search\SearchContext;
use Instafind\Sdk\Search\SearchQuery;
use Instafind\Sdk\Search\SortOrder;
use Instafind\Sdk\Search\SuggestionFill;
use Instafind\Sdk\Testing\MockTransport;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against the vendored OpenAPI document (openapi/instafind-v1.json,
 * a copy of the backend's docs/openapi/instafind-v1.json; refresh it with
 * scripts/update-openapi.sh):
 *
 * - every request the SDK sends is an operation of the document, with
 *   query parameters the operation declares;
 * - every operation of the document is one the SDK calls;
 * - the response fields the typed objects read are in the document's schemas.
 *
 * Where the document lags behind the backend, the gap is listed below and the
 * test fails once the document closes it, so the list stays true.
 */
final class OpenApiContractTest extends TestCase
{
    private const DOCUMENT = __DIR__ . '/../../openapi/instafind-v1.json';

    /**
     * Query parameters the SDK sends that the document does not declare yet,
     * per "METHOD path". The backend accepts them (SearchController,
     * CampaignSearchController).
     */
    private const UNDECLARED_PARAMETERS = [
        'GET /api/public/v1/search' => ['page', 'sid'],
        'GET /api/public/v1/search/campaign' => ['sort', 'order', 'facets', 'facet_size', 'facet_sort', 'strict', 'sid'],
    ];

    /**
     * Response fields the SDK reads that a schema does not list yet. The
     * backend sends them (FieldDefinitionResource).
     */
    private const UNDOCUMENTED_FIELDS = [
        'FieldDefinitionResource' => ['type_label', 'type_group'],
    ];

    /** Schema => the fields the SDK's typed objects read. */
    private const FIELDS_READ = [
        'PublicSearchResponse' => ['results', 'banners', 'detected', 'merchandising', 'redirect', 'related', 'query_used', 'category_matches', 'applied', 'reason'],
        'ProductWriteResult' => ['successful', 'filtered', 'pending', 'failed', 'results', 'meta'],
        'SearchIndexResource' => ['id', 'name', 'name_override', 'source', 'lifecycle_state', 'rebuild', 'langcode', 'status', 'field_definitions', 'created_at', 'updated_at'],
        'FieldDefinitionResource' => ['field_id', 'original_key', 'type', 'type_label', 'type_group', 'token', 'is_custom', 'split_delimiter', 'rebuild_pending', 'sample_values'],
        'StoredProduct' => ['product_uid', 'title', 'raw_data', 'processed_data', 'filtered', 'pending', 'updated_at'],
        'WebsiteResource' => ['id', 'slug', 'organization_id', 'name', 'domain', 'search_indices_count', 'created_at', 'updated_at'],
        'ContentSnippetPublicResource' => ['slug', 'name', 'content', 'placement', 'tags'],
    ];

    /** @var array<string, mixed> */
    private static array $document = [];

    public static function setUpBeforeClass(): void
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents(self::DOCUMENT), true, 512, JSON_THROW_ON_ERROR);
        self::$document = $document;
    }

    public function testEveryRequestIsAnOperationAndEveryOperationIsCalled(): void
    {
        $operations = self::operations();
        $called = [];

        foreach (self::sendEverything() as $request) {
            $parts = parse_url($request->url());
            self::assertIsArray($parts);
            $path = $parts['path'] ?? '';
            $operation = self::match($operations, $request->method(), $path);
            self::assertNotNull($operation, 'Not in the OpenAPI document: ' . $request->method() . ' ' . $path);
            $called[$operation['key']] = true;

            parse_str($parts['query'] ?? '', $query);
            $undeclared = self::UNDECLARED_PARAMETERS[$operation['key']] ?? [];
            foreach (array_keys($query) as $name) {
                $name = (string) $name;
                $declared = in_array($name, $operation['parameters'], true) || in_array($name . '[]', $operation['parameters'], true);
                if ($declared) {
                    self::assertNotContains($name, $undeclared, "The document now declares $name on {$operation['key']}: remove it from UNDECLARED_PARAMETERS.");
                    continue;
                }
                // Field filters (`brand[]=…`) are open-ended; the document cannot list them.
                if (is_array($query[$name]) && array_is_list($query[$name])) {
                    continue;
                }
                self::assertContains($name, $undeclared, "{$operation['key']} does not declare the query parameter $name.");
            }
        }

        $missing = array_diff(array_keys($operations), array_keys($called));
        self::assertSame([], array_values($missing), 'Operations of the document the SDK does not call.');
    }

    public function testTheFieldsTheSdkReadsAreInTheSchemas(): void
    {
        $schemas = self::dig(self::$document, 'components', 'schemas');
        self::assertIsArray($schemas);
        foreach (self::FIELDS_READ as $schema => $fields) {
            self::assertIsArray($schemas[$schema] ?? null, "The document has no schema $schema.");
            $properties = array_keys(is_array($schemas[$schema]['properties'] ?? null) ? $schemas[$schema]['properties'] : []);
            $gaps = self::UNDOCUMENTED_FIELDS[$schema] ?? [];
            foreach ($fields as $field) {
                if (in_array($field, $properties, true)) {
                    self::assertNotContains($field, $gaps, "$schema now documents $field: remove it from UNDOCUMENTED_FIELDS.");
                } else {
                    self::assertContains($field, $gaps, "$schema has no field $field, which the SDK reads.");
                }
            }
        }
    }

    public function testTheKeyResponseHasWhatKeyInfoReads(): void
    {
        $schema = self::dig(self::$document, 'paths', '/api/integration/v1/key', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'data', 'properties');
        self::assertIsArray($schema);
        foreach (['id', 'kind', 'name', 'prefix', 'client', 'connected_url', 'website', 'scopes', 'all_indexes', 'search_index_ids', 'expires_at', 'created_at'] as $field) {
            self::assertArrayHasKey($field, $schema);
        }
    }

    /**
     * Call every method of both APIs once, with every parameter the typed
     * builders can set.
     *
     * @return list<Request>
     */
    private static function sendEverything(): array
    {
        $transport = new MockTransport(static fn(Request $r): Response => $r->method() === 'DELETE'
            ? new Response(204)
            : new Response(200, [], '{"data":{}}'));
        $options = new ClientOptions(transport: $transport);
        $public = new PublicApi('https://platform.example', $options);
        $integration = new IntegrationApi('https://platform.example', new StaticKey('ifk_x'), $options);
        $index = 'b5c1c9c8-6f0e-4c3a-9a51-0d3c1c7a0001';
        $website = 'a1a1a1a1-6f0e-4c3a-9a51-0d3c1c7a0002';

        $full = SearchQuery::text('shoe')->limit(10)->page(2)->sort('price', SortOrder::Desc)->context(SearchContext::Results)
            ->filter('brand', ['A'])->range('price', 1, 9)->facets(['brand'])->facetSize(5)->facetSort(FacetSort::Count)
            ->highlight()->strict()->browse()->categoryMatches(2)->sessionId('s')->ignoreDetected(['brand:A']);
        $campaign = SearchQuery::text()->limit(10)->page(2)->sort('price')->filter('brand', ['A'])->facets(['brand'])->facetSize(5)
            ->facetSort(FacetSort::Key)->strict()->sessionId('s');

        $public->search($index, $full);
        $public->searchMany($index, ['a' => $full]);
        $public->campaign($index, 'bf', $campaign);
        $public->campaignMany($index, 'bf', [$campaign]);
        $public->campaignEvent($index, 'bf', CampaignEvent::View);
        $public->productSuggestions($index, ProductSuggestionsQuery::forProducts(['1'])->limit(4)->fill(SuggestionFill::Category)->filter('brand', ['A']));
        $public->relatedEvent($index);
        $public->imageSearch($index, '1', SearchQuery::text()->limit(10)->page(2)->sort('price')->filter('brand', ['A']));
        $public->suggestions($index, 'sh');
        $public->snippets($index);
        $public->facetConfig($index);
        $public->keywords($index);

        $integration->key();
        $integration->website($website);
        $integration->createIndex($website, IndexSchema::fromFields(['id' => FieldType::Identifier]), 'el', 'x');
        $integration->index($index);
        $integration->fieldDefinitions($index);
        $integration->addField($index, 'colour', FieldType::Color);
        $integration->updateField($index, 'colour', ['type' => 'attribute']);
        $integration->searchBehaviors($index);
        $integration->putSearchBehaviors($index, []);
        $integration->putFilterValueTree($index, 'category', []);
        $integration->setCampaignPage($index, null);
        $integration->setUpDefaultFilters($index, []);
        $integration->setUpDefaultTemplate($index, 'x');
        $integration->storedProducts($index, 1, 10, 'x');
        $integration->storedProduct($index, '1');
        $integration->analytics($index, 7);
        $integration->summary($index);
        $integration->sendOrders($website, [['reference' => '1']]);
        $integration->forgetCustomer($website, str_repeat('a', 64));
        $integration->upsertProducts($index, [['id' => '1']], RefreshMode::WaitFor);
        $integration->upsertProduct($index, '1', ['id' => '1'], RefreshMode::Immediate);
        $integration->deleteProduct($index, '1');

        return $transport->requests();
    }

    /**
     * @return array<string, array{key: string, method: string, regex: string, parameters: list<string>}>
     */
    private static function operations(): array
    {
        $out = [];
        $paths = self::dig(self::$document, 'paths');
        self::assertIsArray($paths);
        foreach ($paths as $path => $operations) {
            if (!is_array($operations)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || !is_array($operation)) {
                    continue;
                }
                $parameters = [];
                foreach (is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [] as $parameter) {
                    if (is_array($parameter) && ($parameter['in'] ?? '') === 'query' && is_string($parameter['name'] ?? null)) {
                        $parameters[] = $parameter['name'];
                    }
                }
                $key = strtoupper($method) . ' ' . $path;
                $out[$key] = [
                    'key' => $key,
                    'method' => strtoupper($method),
                    'regex' => '#^' . preg_replace('#\\\\\{[^/]+\\\\\}#', '[^/]+', preg_quote((string) $path, '#')) . '$#',
                    'parameters' => $parameters,
                ];
            }
        }

        return $out;
    }

    /**
     * A value deep in the document, null when the path is not there.
     *
     * @param array<array-key, mixed> $data
     */
    private static function dig(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, array{key: string, method: string, regex: string, parameters: list<string>}> $operations
     *
     * @return array{key: string, method: string, regex: string, parameters: list<string>}|null
     */
    private static function match(array $operations, string $method, string $path): ?array
    {
        foreach ($operations as $operation) {
            if ($operation['method'] === $method && preg_match($operation['regex'], $path) === 1) {
                return $operation;
            }
        }

        return null;
    }
}
