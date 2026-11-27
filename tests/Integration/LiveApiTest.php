<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Integration;

use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Integration\RefreshMode;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\PublicApi;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;
use Instafind\Sdk\Search\ProductSuggestionsQuery;
use Instafind\Sdk\Search\SearchQuery;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against a live Instafind: the "PHP SDK CI" website on production,
 * with its own least-privilege site key. Run by .github/workflows/integration.yml;
 * skipped without INSTAFIND_CI_API_KEY.
 *
 * Apart from search analytics, which each run adds to, what it leaves on
 * the website is the same after every run:
 * - One index, INSTAFIND_CI_INDEX_ID (a repository variable). The API has
 *   no way to list a website's indexes, so the id cannot be looked up by
 *   name. Without the variable, the run creates the index, finishes, and
 *   then fails, asking for the variable to be set to the new index's id.
 *   The index's campaign page, default filters and default template are
 *   set to the same values every run.
 * - It writes the same three products every run and deletes them at the end.
 * - It sends one fixed order (same reference and dates every run, so a
 *   repeat replaces it: `ok`, or `stale` if a newer copy is stored). The
 *   order stays; its fixed customer key is forgotten.
 */
final class LiveApiTest extends TestCase
{
    public const INDEX_NAME = 'php-sdk-ci';

    /** Checked before anything is written, so other websites' keys write nothing. */
    public const WEBSITE_NAME = 'PHP SDK CI';

    private const PRODUCT_IDS = ['sdk-ci-1', 'sdk-ci-2', 'sdk-ci-3'];

    private const ORDER_REFERENCE = 'sdk-ci-order-1';

    private const CUSTOMER_KEY = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    private static ?IntegrationApi $api = null;

    private static ?PublicApi $public = null;

    private static string $indexId = '';

    private static bool $createdIndex = false;

    /** @var list<string> */
    private static array $written = [];

    public static function setUpBeforeClass(): void
    {
        $key = self::env('INSTAFIND_CI_API_KEY');
        if ($key === '') {
            return;
        }
        $base = self::env('INSTAFIND_CI_BASE_URL', 'https://platform.instafind.io');
        $userAgent = new UserAgent('instafind-php-sdk-ci', self::env('GITHUB_RUN_ID', 'local'), 'https://github.com/instafind-io/instafind-php-sdk');
        self::$api = new IntegrationApi($base, new StaticKey($key), new ClientOptions(userAgent: $userAgent));
        self::$public = new PublicApi(self::env('INSTAFIND_CI_PUBLIC_BASE_URL', $base), new ClientOptions(userAgent: $userAgent, timeout: 15.0));
    }

    protected function setUp(): void
    {
        if (self::$api === null) {
            self::markTestSkipped('Set INSTAFIND_CI_API_KEY (and INSTAFIND_CI_WEBSITE_ID) to run against a live Instafind.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$api === null || self::$indexId === '') {
            return;
        }
        foreach (self::$written as $productId) {
            self::$api->deleteProduct(self::$indexId, $productId);
        }
        self::$written = [];
    }

    public function testTheKeyBelongsToTheCiWebsite(): void
    {
        $key = $this->api()->key();

        self::assertSame(self::env('INSTAFIND_CI_WEBSITE_ID'), $key->websiteId());
        foreach (['indexes:read', 'indexes:manage', 'products:read', 'products:write', 'products:delete', 'analytics:read', 'orders:write'] as $scope) {
            self::assertTrue($key->hasScope($scope), "The CI key lacks $scope.");
        }
        $website = $this->api()->website($key->websiteId());
        self::assertSame($key->websiteId(), $website->id());
        self::assertSame(self::WEBSITE_NAME, $website->name(), 'The CI key belongs to another website.');
    }

    #[Depends('testTheKeyBelongsToTheCiWebsite')]
    public function testTheIndexIsReused(): void
    {
        $schema = IndexSchema::fromFields([
            'id' => FieldType::Identifier,
            'title' => FieldType::Title,
            'price' => FieldType::Price,
            'brand' => FieldType::Brand,
            'category' => FieldType::Category,
        ]);
        $indexId = self::env('INSTAFIND_CI_INDEX_ID');
        if ($indexId === '') {
            // Only on a website without indexes, so a lost variable cannot
            // add an index on every run.
            $count = $this->api()->website(self::env('INSTAFIND_CI_WEBSITE_ID'))->searchIndexCount();
            self::assertSame(0, $count, 'Set the repository variable INSTAFIND_CI_INDEX_ID to the CI index; the website already has ' . $count . ' index(es).');
            $index = $this->api()->createIndex(self::env('INSTAFIND_CI_WEBSITE_ID'), $schema, 'en', self::INDEX_NAME);
            self::$createdIndex = true;
            fwrite(STDERR, "\nCreated the CI index " . $index->id() . ". Set the repository variable INSTAFIND_CI_INDEX_ID to it.\n");
        } else {
            $index = $this->api()->index($indexId);
            self::assertSame(self::INDEX_NAME, $index->name(), 'INSTAFIND_CI_INDEX_ID is not the CI index.');
        }
        self::$indexId = $index->id();
        self::assertSame('api', $index->source());

        $have = array_map(static fn($f): string => $f->fieldId(), $this->api()->fieldDefinitions(self::$indexId));
        foreach ($schema->types() as $fieldId => $type) {
            if (!in_array($fieldId, $have, true)) {
                $this->api()->addField(self::$indexId, $fieldId, $type);
            }
        }
        self::assertNotSame([], $this->api()->searchBehaviors(self::$indexId)->all());
    }

    #[Depends('testTheIndexIsReused')]
    public function testProductsAreWrittenAndReadBack(): void
    {
        $products = [];
        foreach (self::PRODUCT_IDS as $i => $id) {
            $products[] = ['id' => $id, 'title' => 'SDK CI product ' . ($i + 1), 'price' => 10 + $i, 'brand' => 'SDK CI', 'category' => ['CI']];
        }
        self::$written = self::PRODUCT_IDS;

        $result = $this->api()->bulkLoader(2)->load(self::$indexId, $products);
        self::assertTrue($result->isClean(), 'Rejected: ' . json_encode(array_map(static fn($e): ?string => $e->reason(), $result->errors())));
        self::assertSame(3, $result->successful() + $result->pending());
        self::assertSame(2, $result->batches());

        $one = $this->api()->upsertProduct(self::$indexId, 'sdk-ci-1', $products[0], RefreshMode::WaitFor);
        self::assertTrue($one->isClean());

        $stored = $this->api()->storedProduct(self::$indexId, 'sdk-ci-2');
        self::assertNotNull($stored);
        self::assertSame('SDK CI product 2', $stored->rawData()['title'] ?? null);
        self::assertNull($this->api()->storedProduct(self::$indexId, 'sdk-ci-does-not-exist'));
        self::assertGreaterThanOrEqual(3, $this->api()->countProducts(self::$indexId));
        self::assertGreaterThanOrEqual(1, $this->api()->storedProducts(self::$indexId, 1, 10, 'SDK CI')->total());
    }

    #[Depends('testProductsAreWrittenAndReadBack')]
    public function testPublicSearchFindsThem(): void
    {
        $public = $this->publicApi();

        $result = $public->search(self::$indexId, SearchQuery::text('SDK CI product')->limit(10));
        $ids = array_map(static fn($hit): mixed => $hit->get('id'), $result->hits());
        foreach (self::PRODUCT_IDS as $id) {
            self::assertContains($id, $ids);
        }

        $many = $public->searchMany(self::$indexId, ['all' => SearchQuery::text('SDK CI')->limit(1), 'none' => SearchQuery::text('zzqqxx nothing')->limit(1)]);
        self::assertInstanceOf(\Instafind\Sdk\Search\SearchResult::class, $many['all']);
        self::assertInstanceOf(\Instafind\Sdk\Search\SearchResult::class, $many['none']);

        // These may answer empty on a CI index; they must answer.
        $public->productSuggestions(self::$indexId, ProductSuggestionsQuery::forProducts(['sdk-ci-1'])->limit(2));
        $public->suggestions(self::$indexId, 'sdk');
        $public->snippets(self::$indexId);
        $public->facetConfig(self::$indexId);
        $public->keywords(self::$indexId);
        $this->addToAssertionCount(1);
    }

    #[Depends('testTheIndexIsReused')]
    public function testAnUnknownIndexIsIndexNotFound(): void
    {
        $unknown = '00000000-0000-4000-8000-000000000000';
        try {
            $this->publicApi()->search($unknown, SearchQuery::text('x'));
            self::fail('No exception from public search');
        } catch (IndexNotFoundException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->api()->index($unknown);
            self::fail('No exception from the integration API');
        } catch (IndexNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Depends('testTheIndexIsReused')]
    public function testShopSetupAndReports(): void
    {
        $this->api()->setCampaignPage(self::$indexId, 'https://sdk-ci.example.com/campaign/{name}');
        $this->api()->setUpDefaultFilters(self::$indexId, [['field_id' => 'brand', 'label' => 'Brand']]);
        $this->api()->setUpDefaultTemplate(self::$indexId, '<div class="sdk-ci">{{ title }}</div>');

        self::assertGreaterThanOrEqual(1, $this->api()->analytics(self::$indexId, 1)->days());
        self::assertSame(self::$indexId, $this->api()->summary(self::$indexId)->raw()['search_index_id'] ?? null);
    }

    #[Depends('testTheKeyBelongsToTheCiWebsite')]
    public function testTheFixedOrder(): void
    {
        $result = $this->api()->sendOrders(self::env('INSTAFIND_CI_WEBSITE_ID'), [[
            'reference' => self::ORDER_REFERENCE,
            'customer_key' => self::CUSTOMER_KEY,
            'status' => 'paid',
            'currency' => 'EUR',
            'placed_at' => '2026-11-27T12:00:00+00:00',
            'updated_at' => '2026-11-27T12:00:00+00:00',
            'lines' => [['product_id' => 'sdk-ci-1', 'name' => 'SDK CI product 1', 'quantity' => 1, 'net_total' => 10, 'tax_total' => 2.4]],
        ]]);

        self::assertTrue($result->isClean(), 'Order refused: ' . json_encode($result->raw()));
        self::assertContains($result->results()[0]->status(), ['ok', 'stale']);
        self::assertGreaterThanOrEqual(0, $this->api()->forgetCustomer(self::env('INSTAFIND_CI_WEBSITE_ID'), self::CUSTOMER_KEY));
    }

    /**
     * Last, so the products are deleted by the run itself (tearDownAfterClass
     * only catches what an earlier failure left).
     */
    #[Depends('testPublicSearchFindsThem')]
    public function testTheProductsAreDeleted(): void
    {
        foreach (self::PRODUCT_IDS as $id) {
            $this->api()->deleteProduct(self::$indexId, $id);
        }
        self::$written = [];
        self::assertNull($this->api()->storedProduct(self::$indexId, 'sdk-ci-1'));
    }

    #[Depends('testTheIndexIsReused')]
    public function testTheIndexVariableIsSet(): void
    {
        self::assertFalse(self::$createdIndex, 'This run created the CI index ' . self::$indexId . ': set the repository variable INSTAFIND_CI_INDEX_ID to it, or every run creates another one.');
    }

    private function api(): IntegrationApi
    {
        self::assertNotNull(self::$api);

        return self::$api;
    }

    private function publicApi(): PublicApi
    {
        self::assertNotNull(self::$public);

        return self::$public;
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
