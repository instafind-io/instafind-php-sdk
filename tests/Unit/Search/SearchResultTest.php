<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Search;

use Instafind\Sdk\Search\CampaignResult;
use Instafind\Sdk\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testReadsAFullAnswer(): void
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/responses/search.json'), true);
        $result = new SearchResult($raw);

        self::assertSame(42, $result->total());
        self::assertTrue($result->totalIsExact());
        self::assertFalse($result->isEmpty());
        self::assertCount(2, $result->hits());
        self::assertSame('Running shoe', $result->hits()[0]->get('title'));
        self::assertSame(['title' => ['<strong>Running</strong> shoe']], $result->hits()[0]->highlight());
        self::assertNull($result->hits()[0]->suggestionSource());

        $brand = $result->facet('brand');
        self::assertNotNull($brand);
        self::assertSame(3, $brand->otherCount());
        self::assertSame('Nike', $brand->buckets()[1]->value());
        self::assertSame(12, $brand->buckets()[1]->count());
        self::assertSame('USA', $brand->buckets()[1]->subtitle());

        self::assertSame(19.5, $result->ranges()['price']->min());
        self::assertSame(240.0, $result->ranges()['price']->max());
        self::assertSame('<p>Sale</p>', $result->banners()[0]->html());
        self::assertNull($result->redirect());
        self::assertSame(['cheap'], $result->queryUsed()?->droppedWords());
        self::assertSame([['typed' => 'runing', 'shown' => 'running']], $result->queryUsed()->corrections());
        self::assertSame(['Sport', 'Shoes'], $result->categoryMatches()[0]->path());
        self::assertSame('p2', $result->promoted()[0]['id']);
        self::assertSame('p9', $result->related()[0]->get('id'));
        self::assertSame(['price'], $result->appliedFilters());
        self::assertSame('unknown_field', $result->ignoredFilters()[0]->reason());
        self::assertSame('boost', $result->detected()[0]['action']);
        self::assertNull($result->reason());
        self::assertSame($raw, $result->raw());
    }

    public function testAnEmptyAnswer(): void
    {
        $result = new SearchResult(['results' => [], 'reason' => 'index_disabled']);

        self::assertSame(0, $result->total());
        self::assertSame([], $result->hits());
        self::assertSame([], $result->facets());
        self::assertTrue($result->isEmpty());
        self::assertSame('index_disabled', $result->reason());
        self::assertNull($result->queryUsed());
    }

    public function testACampaignWrapsItsSearch(): void
    {
        $result = new CampaignResult([
            'results' => ['hits' => ['total' => ['value' => 1, 'relation' => 'eq'], 'hits' => [['_source' => ['id' => 'p']]]]],
            'campaign' => [
                'name' => 'black-friday', 'state' => 'active', 'title' => 'Black Friday', 'intro' => '',
                'banner' => ['desktop_url' => 'https://img/d.jpg', 'mobile_url' => null, 'link' => '', 'alt' => 'BF'],
                'tiles' => [['label' => 'Shoes', 'field' => 'category', 'value' => 'shoes']],
                'list' => 'selection', 'starts_at' => '2026-11-27T00:00:00+00:00', 'message_key' => null, 'ended_message' => null, 'redirect' => null,
            ],
        ]);

        self::assertSame(1, $result->result()->total());
        $campaign = $result->campaign();
        self::assertNotNull($campaign);
        self::assertTrue($campaign->isActive());
        self::assertSame('https://img/d.jpg', $campaign->banner()?->desktopUrl());
        self::assertNull($campaign->banner()->mobileUrl());
        self::assertSame('2026-11-27', $campaign->startsAt()?->format('Y-m-d'));
        self::assertSame('shoes', $campaign->tiles()[0]['value']);
        self::assertNull((new CampaignResult(['results' => []]))->campaign());
    }
}
