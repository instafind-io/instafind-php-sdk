<?php

declare(strict_types=1);

namespace Instafind\Sdk;

use Instafind\Sdk\Auth\Credential;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Exception\InvalidSchemaException;
use Instafind\Sdk\Http\ApiClient;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\RetryPolicy;
use Instafind\Sdk\Indexing\BulkLoader;
use Instafind\Sdk\Integration\Analytics;
use Instafind\Sdk\Integration\BulkWriteResult;
use Instafind\Sdk\Integration\DefaultFiltersResult;
use Instafind\Sdk\Integration\DefaultTemplateResult;
use Instafind\Sdk\Integration\FieldDefinition;
use Instafind\Sdk\Integration\IndexSummary;
use Instafind\Sdk\Integration\KeyInfo;
use Instafind\Sdk\Integration\OrdersResult;
use Instafind\Sdk\Integration\RefreshMode;
use Instafind\Sdk\Integration\SearchBehaviors;
use Instafind\Sdk\Integration\SearchIndex;
use Instafind\Sdk\Integration\StoredProduct;
use Instafind\Sdk\Integration\StoredProductsPage;
use Instafind\Sdk\Integration\Website;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;
use Instafind\Sdk\Support\Data;

/**
 * Instafind's integration API (`/api/integration/v1`): indexes, their
 * settings, products, orders and analytics, with a site API key or an OAuth
 * connection as credential.
 *
 * Temporary answers (429, 503, the "wait and retry" 409s) are repeated up to
 * three times; {@see ClientOptions} changes that. Requests time out after 30
 * seconds by default.
 *
 * On the routes of one index, an index the credential cannot reach (deleted,
 * being deleted, outside its grant) throws {@see IndexNotFoundException}.
 */
final class IntegrationApi
{
    public const BASE_PATH = '/api/integration/v1';

    public const DEFAULT_TIMEOUT = 30.0;

    /** The most products one write takes. */
    public const MAX_BATCH = 500;

    /** The most orders one call takes. */
    public const MAX_ORDERS = 100;

    private readonly ApiClient $api;

    public function __construct(string $baseUrl, Credential $credential, ?ClientOptions $options = null)
    {
        $options ??= new ClientOptions();
        $this->api = new ApiClient(
            $baseUrl,
            $options->transport(),
            $options->userAgent(),
            $options->retryPolicy(new RetryPolicy()),
            $options->timeout(self::DEFAULT_TIMEOUT),
            $credential,
            $options->sleeper(),
        );
    }

    // ------------------------------------------------------------ credential

    /**
     * What the credential is: website, scopes, index grant, expiry.
     *
     * @throws InstafindException
     */
    public function key(): KeyInfo
    {
        return new KeyInfo(Data::map($this->get('/key'), 'data'));
    }

    /**
     * @throws InstafindException
     */
    public function website(string $websiteId): Website
    {
        return new Website(Data::map($this->get('/websites/' . rawurlencode($websiteId)), 'data'));
    }

    // ---------------------------------------------------------------- indexes

    /**
     * Create an API-sourced index. Needs `indexes:manage` on a key that covers
     * all indexes. Not idempotent and never repeated by the SDK: after a
     * timeout, look for the index in Instafind before trying again.
     *
     * @throws InvalidSchemaException on a langcode Instafind has no analyser for
     * @throws InstafindException
     */
    public function createIndex(string $websiteId, IndexSchema $schema, string $langcode, ?string $name = null): SearchIndex
    {
        IndexSchema::assertSupportedLangcode($langcode);
        $body = ['langcode' => $langcode, 'fields' => $schema->fields()];
        if ($name !== null) {
            $body['name'] = $name;
        }
        $request = $this->api->request('POST', self::BASE_PATH . '/websites/' . rawurlencode($websiteId) . '/search-indices', [], $body, null, false);

        return new SearchIndex(Data::map($this->api->send($request, RetryPolicy::none()), 'data'));
    }

    /**
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function index(string $indexId): SearchIndex
    {
        return new SearchIndex(Data::map($this->forIndex($indexId, 'GET', ''), 'data'));
    }

    /**
     * @return list<FieldDefinition>
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function fieldDefinitions(string $indexId): array
    {
        return array_map(static fn(array $f): FieldDefinition => new FieldDefinition($f), Data::maps($this->forIndex($indexId, 'GET', '/field-definitions'), 'data'));
    }

    /**
     * Add a field to an index. Products may carry it once this returns.
     *
     * @throws InvalidSchemaException on a field id the API would refuse
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function addField(string $indexId, string $fieldId, FieldType $type, ?string $token = null): FieldDefinition
    {
        IndexSchema::assertFieldId($fieldId);
        $body = ['field_id' => $fieldId, 'type' => $type->value];
        if ($token !== null) {
            $body['token'] = $token;
        }

        return new FieldDefinition(Data::map($this->forIndex($indexId, 'POST', '/field-definitions', $body), 'data'));
    }

    /**
     * Change a field: `type`, `token`, `split_delimiter`. A type change waits
     * for a rebuild of the index ({@see FieldDefinition::isRebuildPending()}).
     * An unknown field answers a plain {@see ApiException} (404 `http_404`).
     *
     * @param array{type?: string, token?: string|null, split_delimiter?: string|null} $changes
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function updateField(string $indexId, string $fieldId, array $changes): FieldDefinition
    {
        return new FieldDefinition(Data::map($this->forIndex($indexId, 'PUT', '/field-definitions/' . rawurlencode($fieldId), $changes), 'data'));
    }

    /**
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function searchBehaviors(string $indexId): SearchBehaviors
    {
        return new SearchBehaviors($this->forIndex($indexId, 'GET', '/search-behaviors'));
    }

    /**
     * Replace the search behaviours. The API needs every field of the index,
     * in the shape {@see SearchBehaviors::all()} returns.
     *
     * @param array<string, array<string, mixed>> $behaviors field id => behaviour
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function putSearchBehaviors(string $indexId, array $behaviors): SearchBehaviors
    {
        return new SearchBehaviors(Data::map($this->forIndex($indexId, 'PUT', '/search-behaviors', ['behaviors' => $behaviors]), 'data'));
    }

    /**
     * Replace what the values of a filter stand for: their names and parents
     * (a category tree). At most 5000 nodes.
     *
     * @param list<array{value: string, label: string, parent?: string|null}> $nodes
     *
     * @return int the nodes stored
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function putFilterValueTree(string $indexId, string $fieldId, array $nodes): int
    {
        return Data::int(Data::map($this->forIndex($indexId, 'PUT', '/filter-value-trees/' . rawurlencode($fieldId), ['nodes' => $nodes]), 'data'), 'nodes');
    }

    /**
     * Where the shop's campaign pages are: an https address with `{name}` for
     * the campaign. It cannot be cleared, only replaced. Needs `indexes:manage`.
     *
     * @throws \InvalidArgumentException on an empty address
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function setCampaignPage(string $indexId, string $url): void
    {
        if (trim($url) === '') {
            throw new \InvalidArgumentException('Give the campaign page address; it cannot be cleared.');
        }
        $this->forIndex($indexId, 'PUT', '/campaign-page', ['campaign_page_url' => $url]);
    }

    /**
     * Propose the index's Filters tab: these fields, in this order, with
     * these names. Instafind keeps those the products make worth showing,
     * once the catalogue is in, and never replaces a saved tab.
     *
     * @param list<array{field_id: string, label?: string}> $filters at most 50
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function setUpDefaultFilters(string $indexId, array $filters): DefaultFiltersResult
    {
        return new DefaultFiltersResult(Data::map($this->forIndex($indexId, 'POST', '/default-filters', ['filters' => $filters], true), 'data'));
    }

    /**
     * Propose the templates of the results page and the live search. A view
     * Instafind already has set up stays as it is.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function setUpDefaultTemplate(string $indexId, ?string $results = null, ?string $liveSearch = null): DefaultTemplateResult
    {
        if ($results === null && $liveSearch === null) {
            throw new \InvalidArgumentException('Send the results template, the live search template or both.');
        }
        $body = array_filter(['results' => $results, 'live_search' => $liveSearch], static fn(?string $t): bool => $t !== null);

        return new DefaultTemplateResult(Data::map($this->forIndex($indexId, 'POST', '/default-template', $body, true), 'data'));
    }

    // --------------------------------------------------------- stored products

    /**
     * A page of what the index holds. Pages end at the 10,000th product.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function storedProducts(string $indexId, int $page = 1, int $perPage = 50, ?string $search = null): StoredProductsPage
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Pages start at 1 and hold 1 to 100 products.');
        }

        return new StoredProductsPage($this->forIndex($indexId, 'GET', '/stored-products', null, null, ['page' => $page, 'per_page' => $perPage, 'search' => $search]));
    }

    /**
     * One product as the index holds it, null when it holds none by that id.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function storedProduct(string $indexId, string $productId): ?StoredProduct
    {
        try {
            return new StoredProduct(Data::map($this->forIndex($indexId, 'GET', '/stored-products/' . rawurlencode($productId)), 'data'));
        } catch (ApiException $e) {
            if ($e->errorCode() === 'product_not_found') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * How many products the index holds (its durable store; search catches
     * up within seconds).
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function countProducts(string $indexId): int
    {
        return $this->storedProducts($indexId, 1, 1)->total();
    }

    // ---------------------------------------------------------------- reports

    /**
     * Search analytics of the last `$days` days (1–30). Needs `analytics:read`.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function analytics(string $indexId, int $days = 7): Analytics
    {
        if ($days < 1 || $days > 30) {
            throw new \InvalidArgumentException('Analytics cover 1 to 30 days.');
        }
        $data = $this->forIndex($indexId, 'GET', '/analytics', null, null, ['days' => $days]);

        // The answer is not wrapped in `data`; accept it if it ever is.
        return new Analytics(Data::map($data, 'data') !== [] ? Data::map($data, 'data') : $data);
    }

    /**
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function summary(string $indexId): IndexSummary
    {
        $data = $this->forIndex($indexId, 'GET', '/summary');

        return new IndexSummary(Data::map($data, 'data') !== [] ? Data::map($data, 'data') : $data);
    }

    // ----------------------------------------------------------------- orders

    /**
     * Send up to 100 orders, for the revenue reports. Needs `orders:write` on
     * a key covering all of the website's indexes. Orders are keyed by their
     * reference, so sending one again updates it.
     *
     * @param list<array<string, mixed>> $orders
     *
     * @throws InstafindException
     */
    public function sendOrders(string $websiteId, array $orders): OrdersResult
    {
        if ($orders === [] || count($orders) > self::MAX_ORDERS) {
            throw new \InvalidArgumentException('Send 1 to ' . self::MAX_ORDERS . ' orders per call.');
        }
        $request = $this->api->request('POST', self::BASE_PATH . '/websites/' . rawurlencode($websiteId) . '/orders', [], ['orders' => $orders], null, true);

        return new OrdersResult(self::map($this->api->send($request)));
    }

    /**
     * Remove a customer key from every order of the website, for an erasure
     * request. The orders stay. Needs `orders:write`.
     *
     * @return int how many orders carried the key
     *
     * @throws InstafindException
     */
    public function forgetCustomer(string $websiteId, string $customerKey): int
    {
        $path = self::BASE_PATH . '/websites/' . rawurlencode($websiteId) . '/orders/customers/' . rawurlencode($customerKey);

        return Data::int($this->api->send($this->api->request('DELETE', $path)), 'forgotten_orders');
    }

    // --------------------------------------------------------------- products

    /**
     * Write up to 500 products (insert or replace, by their identifier field).
     * Durable when this returns; searchable within seconds, or before it
     * returns with {@see RefreshMode::WaitFor}. An upsert, so the SDK repeats
     * it after a lost answer.
     *
     * @param list<array<string, mixed>> $products
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function upsertProducts(string $indexId, array $products, ?RefreshMode $refresh = null): BulkWriteResult
    {
        if ($products === [] || count($products) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Write 1 to ' . self::MAX_BATCH . ' products per call; BulkLoader batches more.');
        }

        return new BulkWriteResult($this->forIndex($indexId, 'POST', '/products', ['products' => $products], true, ['refresh' => $refresh?->value]));
    }

    /**
     * Write one product under this id.
     *
     * @param array<string, mixed> $product
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function upsertProduct(string $indexId, string $productId, array $product, ?RefreshMode $refresh = null): BulkWriteResult
    {
        if ($product === []) {
            throw new \InvalidArgumentException('A product needs at least one field.');
        }

        return new BulkWriteResult($this->forIndex($indexId, 'PUT', '/products/' . rawurlencode($productId), $product, null, ['refresh' => $refresh?->value]));
    }

    /**
     * Delete one product. One the index never held is not an error.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function deleteProduct(string $indexId, string $productId): void
    {
        try {
            $this->forIndex($indexId, 'DELETE', '/products/' . rawurlencode($productId));
        } catch (ApiException $e) {
            if ($e->errorCode() !== 'product_not_found') {
                throw $e;
            }
        }
    }

    /**
     * Stream any number of products into an index in batches of up to 500.
     */
    public function bulkLoader(int $batchSize = self::MAX_BATCH, bool $waitForFinalBatch = true): BulkLoader
    {
        return new BulkLoader($this, $batchSize, $waitForFinalBatch);
    }

    // --------------------------------------------------------------- plumbing

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return self::map($this->api->send($this->api->request('GET', self::BASE_PATH . $path)));
    }

    /**
     * A call on one index's route: its plain `not_found` means the index.
     *
     * @param array<array-key, mixed>|null $json
     * @param array<string, mixed>         $query
     *
     * @return array<string, mixed>
     */
    private function forIndex(string $indexId, string $method, string $path, ?array $json = null, ?bool $idempotent = null, array $query = []): array
    {
        $request = $this->api->request($method, self::BASE_PATH . '/search-indices/' . rawurlencode($indexId) . $path, $query, $json, null, $idempotent);
        try {
            return self::map($this->api->send($request));
        } catch (ApiException $e) {
            if ($e->status() === 404 && $e->errorCode() === 'not_found') {
                // The integration API answers a plain `not_found` for an
                // index outside the credential's grant or already gone.
                throw new IndexNotFoundException("Instafind has no index $indexId for this credential: " . $e->getMessage(), 404, 'index_not_found', $e->retryAfter(), $e->body(), $e);
            }
            throw $e;
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function map(array $data): array
    {
        /** @var array<string, mixed> */
        return array_is_list($data) ? [] : $data;
    }
}
