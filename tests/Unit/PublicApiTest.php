<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit;

use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\PublicApi;
use Instafind\Sdk\Search\CampaignEvent;
use Instafind\Sdk\Search\CampaignResult;
use Instafind\Sdk\Search\KeywordDisplayMode;
use Instafind\Sdk\Search\ProductSuggestionsQuery;
use Instafind\Sdk\Search\SearchQuery;
use Instafind\Sdk\Search\SearchResult;
use Instafind\Sdk\Search\SnippetPlacement;
use Instafind\Sdk\Testing\MockTransport;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase
{
    private const INDEX = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    private MockTransport $transport;

    private PublicApi $api;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->api = new PublicApi('https://platform.example', new ClientOptions(transport: $this->transport, userAgent: new UserAgent('shop', '1.0')));
    }

    public function testSearch(): void
    {
        $this->transport->queueJson(200, ['results' => ['hits' => ['total' => ['value' => 3, 'relation' => 'eq'], 'hits' => []]]]);

        $result = $this->api->search(self::INDEX, SearchQuery::text('red shoe')->limit(10)->filter('brand', ['A']));

        self::assertSame(3, $result->total());
        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame('https://platform.example/api/public/v1/search?id=' . self::INDEX . '&q=red%20shoe&limit=10&brand[]=A', $request->url());
        self::assertNull($request->header('Authorization'));
        self::assertSame(PublicApi::DEFAULT_TIMEOUT, $request->timeout());
        self::assertStringStartsWith('instafind-php-sdk/', (string) $request->header('User-Agent'));
        self::assertSame($request->url(), $this->api->searchUrl(self::INDEX, SearchQuery::text('red shoe')->limit(10)->filter('brand', ['A'])));
    }

    public function testAnUnknownIndexIsIndexNotFound(): void
    {
        $this->transport->queueJson(404, ['error' => 'index_not_found', 'message' => 'Index not found.']);

        try {
            $this->api->search(self::INDEX, SearchQuery::text('x'));
            self::fail('No exception');
        } catch (IndexNotFoundException $e) {
            self::assertSame('index_not_found', $e->errorCode());
            self::assertSame(404, $e->status());
        }
    }

    public function testAnotherNotFoundIsNotAboutTheIndex(): void
    {
        // A wrong base URL, or a server without the v1 routes.
        $this->transport->queueJson(404, ['message' => 'Not Found']);

        try {
            $this->api->search(self::INDEX, SearchQuery::text('x'));
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(IndexNotFoundException::class, $e);
            self::assertSame(404, $e->status());
        }
    }

    public function testATimeoutOfZeroIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api->search(self::INDEX, SearchQuery::text('x'), 0.0);
    }

    public function testImageSearchRefusesWhatItsEndpointWould(): void
    {
        foreach ([SearchQuery::text()->limit(0), SearchQuery::text()->limit(100)->page(12)] as $i => $query) {
            try {
                $this->api->imageSearch(self::INDEX, 'p1', $query);
                self::fail("Case $i was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testNothingIsRetriedByDefault(): void
    {
        $this->transport->queueJson(503, []);

        $this->expectException(ApiException::class);
        try {
            $this->api->search(self::INDEX, SearchQuery::text('x'));
        } finally {
            self::assertCount(1, $this->transport->requests());
        }
    }

    public function testSearchManyKeepsKeysAndFailures(): void
    {
        $this->transport->queueJson(200, ['results' => ['hits' => ['total' => ['value' => 1, 'relation' => 'eq'], 'hits' => []]]]);
        $this->transport->queueJson(404, ['error' => 'index_not_found', 'message' => 'Index not found.']);
        $this->transport->queueFailure();

        $out = $this->api->searchMany(self::INDEX, ['a' => SearchQuery::text('a'), 'b' => SearchQuery::text('b'), 'c' => SearchQuery::text('c')], 1.5);

        self::assertInstanceOf(SearchResult::class, $out['a']);
        self::assertSame(1, $out['a']->total());
        self::assertInstanceOf(IndexNotFoundException::class, $out['b']);
        self::assertInstanceOf(TransportException::class, $out['c']);
        self::assertSame(1.5, $this->transport->requests()[0]->timeout());
    }

    public function testCampaignLeavesTheTextOut(): void
    {
        $this->transport->queueJson(200, ['results' => [], 'campaign' => ['name' => 'bf', 'state' => 'ended']]);

        $result = $this->api->campaign(self::INDEX, 'bf', SearchQuery::text('ignored')->page(2));

        self::assertSame('ended', $result->campaign()?->state());
        self::assertSame('https://platform.example/api/public/v1/search/campaign?id=' . self::INDEX . '&name=bf&page=2', $this->transport->lastRequest()->url());
    }

    public function testCampaignMany(): void
    {
        $this->transport->queueJson(200, ['results' => [], 'campaign' => ['name' => 'bf']]);
        $out = $this->api->campaignMany(self::INDEX, 'bf', [3 => SearchQuery::text()->page(3)]);

        self::assertInstanceOf(CampaignResult::class, $out[3]);
    }

    public function testEventsAreFormPosts(): void
    {
        $this->transport->queue(new Response(204));
        $this->transport->queue(new Response(204));

        $this->api->campaignEvent(self::INDEX, 'bf', CampaignEvent::Click);
        $campaign = $this->transport->lastRequest();
        $this->api->relatedEvent(self::INDEX);
        $related = $this->transport->lastRequest();

        self::assertSame('POST', $campaign->method());
        self::assertSame('https://platform.example/api/public/v1/search/campaign/event', $campaign->url());
        self::assertSame('application/x-www-form-urlencoded', $campaign->header('Content-Type'));
        self::assertSame('id=' . self::INDEX . '&name=bf&event=click', $campaign->body());
        self::assertSame('https://platform.example/api/public/v1/search/related/event', $related->url());
        self::assertSame('id=' . self::INDEX . '&event=click', $related->body());
    }

    public function testProductSuggestions(): void
    {
        $this->transport->queueJson(200, ['results' => ['hits' => ['total' => ['value' => 1, 'relation' => 'eq'], 'hits' => [['_source' => ['id' => 'p3'], 'suggestion_source' => 'orders']]]]]);

        $result = $this->api->productSuggestions(self::INDEX, ProductSuggestionsQuery::forProducts(['p1', 'p2'])->limit(3));

        self::assertSame('orders', $result->hits()[0]->suggestionSource());
        self::assertSame('https://platform.example/api/public/v1/search/product-suggestions?id=' . self::INDEX . '&product=p1%2Cp2&limit=3', $this->transport->lastRequest()->url());
    }

    public function testImageSearchUsesTheProductAndAnOffset(): void
    {
        $this->transport->queueJson(200, ['results' => ['hits' => ['hits' => [['_source' => ['id' => 'p1']]]]]]);

        $result = $this->api->imageSearch(self::INDEX, 'p1', SearchQuery::text('ignored')->limit(20)->page(3));

        self::assertSame('p1', $result->hits()[0]->get('id'));
        self::assertSame('https://platform.example/api/public/v1/search/image?id=' . self::INDEX . '&q=p1&limit=20&offset=40', $this->transport->lastRequest()->url());
    }

    public function testSuggestionsSnippetsFacetConfigKeywords(): void
    {
        $this->transport->queueJson(200, [['keyword' => 'shoes', 'occurrence' => 12]]);
        $this->transport->queueJson(200, ['data' => [['slug' => 's', 'name' => 'S', 'content' => '<p>x</p>', 'placement' => 'no-results', 'tags' => ['a']]]]);
        $this->transport->queueJson(200, ['data' => ['regions' => ['sidebar' => ['enabled' => true, 'facet_order' => ['brand'], 'facets' => ['brand' => ['label' => 'Brand', 'display' => 'list', 'config' => []]]]], 'rewrites' => ['color' => [['value' => 'red', 'type' => 'color', 'display' => '#f00', 'aliases' => []]]]]]);
        $this->transport->queueJson(200, ['keywords' => [['keyword' => 'boots', 'count' => 4]]]);

        $suggestions = $this->api->suggestions(self::INDEX, 'sh');
        self::assertSame('shoes', $suggestions[0]->keyword());
        self::assertSame(12, $suggestions[0]->occurrence());
        self::assertStringEndsWith('/api/public/v1/search/suggestions?id=' . self::INDEX . '&q=sh', $this->transport->lastRequest()->url());

        $snippets = $this->api->snippets(self::INDEX, SnippetPlacement::NoResults);
        self::assertSame(['a'], $snippets[0]->tags());
        self::assertStringEndsWith('placement=no-results', $this->transport->lastRequest()->url());

        $config = $this->api->facetConfig(self::INDEX);
        self::assertTrue($config->region('sidebar')?->isEnabled());
        self::assertSame(['brand'], $config->region('sidebar')->facetOrder());
        self::assertSame('Brand', $config->region('sidebar')->facets()['brand']['label']);
        self::assertSame('#f00', $config->rewrites()['color'][0]['display']);

        $keywords = $this->api->keywords(self::INDEX, KeywordDisplayMode::Modal);
        self::assertSame('boots', $keywords[0]->keyword());
        self::assertSame('https://platform.example/api/public/v1/keywords?id=' . self::INDEX . '&mode=popular&display_mode=modal', $this->transport->lastRequest()->url());
    }

    public function testEmptyAnswersForAnUnknownIndex(): void
    {
        $this->transport->queueJson(200, []);
        $this->transport->queueJson(200, ['data' => []]);

        self::assertSame([], $this->api->suggestions(self::INDEX, 'x'));
        self::assertSame([], $this->api->facetConfig(self::INDEX)->regions());
    }
}
