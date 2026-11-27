<?php

declare(strict_types=1);

namespace Instafind\Sdk;

use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\IndexNotFoundException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Http\ApiClient;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\RetryPolicy;
use Instafind\Sdk\Search\CampaignEvent;
use Instafind\Sdk\Search\CampaignResult;
use Instafind\Sdk\Search\FacetConfig;
use Instafind\Sdk\Search\Keyword;
use Instafind\Sdk\Search\KeywordDisplayMode;
use Instafind\Sdk\Search\ProductSuggestionsQuery;
use Instafind\Sdk\Search\RelatedEvent;
use Instafind\Sdk\Search\SearchQuery;
use Instafind\Sdk\Search\SearchResult;
use Instafind\Sdk\Search\Snippet;
use Instafind\Sdk\Search\SnippetPlacement;
use Instafind\Sdk\Search\Suggestion;
use Instafind\Sdk\Support\Data;

/**
 * Instafind's public API (`/api/public/v1`): search and what a storefront
 * shows around it. No credential: a leaked search URL carries none.
 *
 * Made for storefront pages, so by default nothing is retried and a request
 * times out after 5 seconds; {@see ClientOptions} changes both. Every method
 * takes an optional `$timeout` for that call alone.
 *
 * On the routes that name an index (search, campaign, product suggestions,
 * image search), an unknown or disabled-for-search index throws
 * {@see IndexNotFoundException}: the API answers 404 `index_not_found`. Any
 * other 404, such as a wrong base URL, stays an {@see ApiException}. Suggestions, snippets, facet config and
 * keywords answer empty for an unknown index, by design.
 */
final class PublicApi
{
    public const BASE_PATH = '/api/public/v1';

    public const DEFAULT_TIMEOUT = 5.0;

    /** The furthest offset image search pages to. */
    private const IMAGE_SEARCH_MAX_OFFSET = 1000;

    private readonly ApiClient $api;

    public function __construct(string $baseUrl, ?ClientOptions $options = null)
    {
        $options ??= new ClientOptions();
        $this->api = new ApiClient(
            $baseUrl,
            $options->transport(),
            $options->userAgent(),
            $options->retryPolicy(RetryPolicy::none()),
            $options->timeout(self::DEFAULT_TIMEOUT),
            null,
            $options->sleeper(),
        );
    }

    /**
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function search(string $indexId, SearchQuery $query, ?float $timeout = null): SearchResult
    {
        return new SearchResult($this->sendMap($this->searchRequest($indexId, $query, $timeout)));
    }

    /**
     * Several searches at once, in parallel. One failing does not fail the others.
     *
     * @template K of array-key
     *
     * @param array<K, SearchQuery> $queries
     *
     * @return array<K, SearchResult|InstafindException>
     */
    public function searchMany(string $indexId, array $queries, ?float $timeout = null): array
    {
        $requests = array_map(fn(SearchQuery $q): Request => $this->searchRequest($indexId, $q, $timeout), $queries);

        return $this->many($requests, static fn(array $data): SearchResult => new SearchResult($data));
    }

    /** The public URL of a search, for a cache key or the browser. */
    public function searchUrl(string $indexId, SearchQuery $query): string
    {
        return $this->api->url(self::BASE_PATH . '/search', ['id' => $indexId] + $query->toArray());
    }

    /**
     * A campaign page: the campaign and its products. The query's text is
     * ignored; its paging, sort and filters apply.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function campaign(string $indexId, string $name, ?SearchQuery $query = null, ?float $timeout = null): CampaignResult
    {
        return new CampaignResult($this->sendMap($this->campaignRequest($indexId, $name, $query, $timeout)));
    }

    /**
     * Several pages of one campaign at once, in parallel.
     *
     * @template K of array-key
     *
     * @param array<K, SearchQuery> $queries
     *
     * @return array<K, CampaignResult|InstafindException>
     */
    public function campaignMany(string $indexId, string $name, array $queries, ?float $timeout = null): array
    {
        $requests = array_map(fn(SearchQuery $q): Request => $this->campaignRequest($indexId, $name, $q, $timeout), $queries);

        return $this->many($requests, static fn(array $data): CampaignResult => new CampaignResult($data));
    }

    /**
     * Record that a shopper saw or clicked a campaign.
     *
     * @throws InstafindException
     */
    public function campaignEvent(string $indexId, string $name, CampaignEvent $event, ?float $timeout = null): void
    {
        $this->api->send($this->api->request('POST', self::BASE_PATH . '/search/campaign/event', [], null, $timeout, null, [
            'id' => $indexId,
            'name' => $name,
            'event' => $event->value,
        ]));
    }

    /**
     * Product suggestions in Instafind's order; each hit says why it was
     * chosen ({@see \Instafind\Sdk\Search\Hit::suggestionSource()}). With
     * suggestions turned off for the index the answer is empty and
     * {@see SearchResult::reason()} says why.
     *
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function productSuggestions(string $indexId, ProductSuggestionsQuery $query, ?float $timeout = null): SearchResult
    {
        $request = $this->api->request('GET', self::BASE_PATH . '/search/product-suggestions', ['id' => $indexId] + $query->toArray(), null, $timeout);

        return new SearchResult($this->sendMap($request));
    }

    /**
     * Record that a shopper clicked a related product.
     *
     * @throws InstafindException
     */
    public function relatedEvent(string $indexId, RelatedEvent $event = RelatedEvent::Click, ?float $timeout = null): void
    {
        $this->api->send($this->api->request('POST', self::BASE_PATH . '/search/related/event', [], null, $timeout, null, [
            'id' => $indexId,
            'event' => $event->value,
        ]));
    }

    /**
     * Products that look like this one: its image is the query. The product
     * itself comes first. The query's text is ignored; its limit (1–100),
     * page (to the 1,000th product), sort and filters apply.
     *
     * @throws \InvalidArgumentException for a limit of 0 or a page past the 1,000th product
     * @throws IndexNotFoundException
     * @throws InstafindException
     */
    public function imageSearch(string $indexId, string $productId, ?SearchQuery $query = null, ?float $timeout = null): SearchResult
    {
        $params = $query === null ? [] : $query->toArray();
        unset($params['q'], $params['page']);
        $limit = $query?->getLimit();
        if ($limit === 0) {
            throw new \InvalidArgumentException('Image search needs a limit of at least 1.');
        }
        $page = $query?->getPage();
        if ($page !== null && $page > 1) {
            $params['offset'] = ($page - 1) * ($limit ?? 10);
            if ($params['offset'] > self::IMAGE_SEARCH_MAX_OFFSET) {
                throw new \InvalidArgumentException('Image search ends at the ' . self::IMAGE_SEARCH_MAX_OFFSET . 'th product.');
            }
        }

        $request = $this->api->request('GET', self::BASE_PATH . '/search/image', ['id' => $indexId, 'q' => $productId] + $params, null, $timeout);

        return new SearchResult($this->sendMap($request));
    }

    /**
     * Keywords shoppers searched for that start like `$query`, at most 10.
     *
     * @return list<Suggestion>
     *
     * @throws InstafindException
     */
    public function suggestions(string $indexId, string $query, ?float $timeout = null): array
    {
        $data = $this->api->send($this->api->request('GET', self::BASE_PATH . '/search/suggestions', ['id' => $indexId, 'q' => $query], null, $timeout));

        return array_map(static fn(array $s): Suggestion => new Suggestion($s), Data::maps(['list' => $data], 'list'));
    }

    /**
     * The active content snippets of one placement, in order.
     *
     * @return list<Snippet>
     *
     * @throws InstafindException
     */
    public function snippets(string $indexId, SnippetPlacement $placement = SnippetPlacement::Presearch, ?float $timeout = null): array
    {
        $data = $this->api->send($this->api->request('GET', self::BASE_PATH . '/search/snippets', ['id' => $indexId, 'placement' => $placement->value], null, $timeout));

        return array_map(static fn(array $s): Snippet => new Snippet($s), Data::maps($data, 'data'));
    }

    /**
     * The index's Filters tab as the widget reads it.
     *
     * @throws InstafindException
     */
    public function facetConfig(string $indexId, ?float $timeout = null): FacetConfig
    {
        $data = $this->api->send($this->api->request('GET', self::BASE_PATH . '/search/facet-config', ['id' => $indexId], null, $timeout));

        return new FacetConfig(Data::map($data, 'data'));
    }

    /**
     * Popular search keywords, as many as the widget's display mode shows.
     *
     * @return list<Keyword>
     *
     * @throws InstafindException
     */
    public function keywords(string $indexId, KeywordDisplayMode $displayMode = KeywordDisplayMode::DropdownList, ?float $timeout = null): array
    {
        $data = $this->api->send($this->api->request('GET', self::BASE_PATH . '/keywords', [
            'id' => $indexId,
            'mode' => 'popular',
            'display_mode' => $displayMode->value,
        ], null, $timeout));

        return array_map(static fn(array $k): Keyword => new Keyword($k), Data::maps($data, 'keywords'));
    }

    private function searchRequest(string $indexId, SearchQuery $query, ?float $timeout): Request
    {
        return $this->api->request('GET', self::BASE_PATH . '/search', ['id' => $indexId] + $query->toArray(), null, $timeout);
    }

    private function campaignRequest(string $indexId, string $name, ?SearchQuery $query, ?float $timeout): Request
    {
        $params = $query === null ? [] : $query->toArray();
        unset($params['q']);

        return $this->api->request('GET', self::BASE_PATH . '/search/campaign', ['id' => $indexId, 'name' => $name] + $params, null, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMap(Request $request): array
    {
        return self::asMap($this->api->send($request));
    }

    /**
     * @template K of array-key
     * @template R
     *
     * @param array<K, Request>                  $requests
     * @param callable(array<string, mixed>): R $wrap
     *
     * @return array<K, R|InstafindException>
     */
    private function many(array $requests, callable $wrap): array
    {
        $out = [];
        foreach ($this->api->sendMany($requests) as $key => $answer) {
            $out[$key] = $answer instanceof InstafindException ? $answer : $wrap(self::asMap($answer));
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function asMap(array $data): array
    {
        /** @var array<string, mixed> */
        return array_is_list($data) ? [] : $data;
    }
}
