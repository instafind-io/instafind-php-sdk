<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Search;

use Instafind\Sdk\Search\FacetSort;
use Instafind\Sdk\Search\ProductSuggestionsQuery;
use Instafind\Sdk\Search\SearchContext;
use Instafind\Sdk\Search\SearchQuery;
use Instafind\Sdk\Search\SortOrder;
use Instafind\Sdk\Search\SuggestionFill;
use Instafind\Sdk\Support\Query;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testBuildsTheParameters(): void
    {
        $query = SearchQuery::text('shoes')
            ->limit(24)->page(2)
            ->sort('price', SortOrder::Desc)
            ->context(SearchContext::Dropdown)
            ->filter('brand', ['Asics', 'Nike'])
            ->range('price', 10, 49.5)
            ->facets(['brand', 'color'])
            ->facetSize(20)
            ->facetSort(FacetSort::Key)
            ->highlight()
            ->categoryMatches(3)
            ->sessionId('s-1')
            ->param('merchandising', 2);

        self::assertSame(
            'q=shoes&limit=24&page=2&sort=price&order=desc&context=dropdown&facets=brand%2Ccolor&facet_sort=key&highlight=1&category_matches=3&sid=s-1'
            . '&facet_size=20&brand[]=Asics&brand[]=Nike&price[]=10-49.5&merchandising=2',
            Query::build($query->toArray()),
        );
        self::assertSame(24, $query->getLimit());
        self::assertSame(2, $query->getPage());
    }

    public function testIsImmutable(): void
    {
        $base = SearchQuery::text('a');
        $base->limit(5)->filter('brand', ['x']);

        self::assertSame(['q' => 'a'], $base->toArray());
    }

    public function testOpenRangesAndPerFieldFacetSize(): void
    {
        $query = SearchQuery::text()->range('price', null, 50)->range('weight', 1.25, null)->facetSize(5, 'brand')->facetSize(9, 'size');

        self::assertSame('facet_size[brand]=5&facet_size[size]=9&price[]=-50&weight[]=1.25-', Query::build($query->toArray()));
    }

    public function testAGlobalAndAPerFieldFacetSizeCombine(): void
    {
        $expected = 'facet_size[%2A]=10&facet_size[brand]=500';
        self::assertSame($expected, Query::build(SearchQuery::text()->facetSize(10)->facetSize(500, 'brand')->toArray()));
        self::assertSame('facet_size[brand]=500&facet_size[%2A]=10', Query::build(SearchQuery::text()->facetSize(500, 'brand')->facetSize(10)->toArray()));
    }

    public function testAFieldHasOneRangeOrOneFilter(): void
    {
        self::assertSame('price[]=60-', Query::build(SearchQuery::text()->range('price', 10, 50)->range('price', 60, null)->toArray()));
        self::assertSame('price[]=10-50', Query::build(SearchQuery::text()->filter('price', [5])->range('price', 10, 50)->toArray()));
        self::assertSame('price[]=5', Query::build(SearchQuery::text()->range('price', 10, 50)->filter('price', [5])->toArray()));
    }

    public function testNamedFiltersWinOverParam(): void
    {
        self::assertSame('brand[]=New', Query::build(SearchQuery::text()->param('brand', ['Old'])->filter('brand', ['New'])->toArray()));
    }

    public function testTurningAFlagOffRemovesIt(): void
    {
        self::assertSame([], SearchQuery::text()->strict()->strict(false)->toArray());
    }

    public function testRefusesReservedFieldsAndBadValues(): void
    {
        foreach ([
            static fn() => SearchQuery::text()->filter('limit', ['1']),
            static fn() => SearchQuery::text()->limit(101),
            static fn() => SearchQuery::text()->page(0),
            static fn() => SearchQuery::text()->range('price', null, null),
            static fn() => SearchQuery::text()->range('price', -5, 10),
            static fn() => SearchQuery::text()->range('price', 120, 50),
            static fn() => SearchQuery::text()->facetSize(5, '*'),
            static fn() => SearchQuery::text()->facetSize(0),
            static fn() => SearchQuery::text()->categoryMatches(11),
        ] as $i => $build) {
            try {
                $build();
                self::fail("Case $i was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testProductSuggestionsQuery(): void
    {
        $query = ProductSuggestionsQuery::forProducts([12, 'ab'])->limit(6)->fill(SuggestionFill::None)->filter('brand', ['X']);

        self::assertSame('product=12%2Cab&limit=6&fill=none&brand[]=X', Query::build($query->toArray()));
        self::assertSame(['12', 'ab'], $query->productIds());
    }

    public function testProductSuggestionsQueryRefusesWhatTheApiWould(): void
    {
        foreach ([
            static fn() => ProductSuggestionsQuery::forProducts([]),
            static fn() => ProductSuggestionsQuery::forProducts(range(1, 21)),
            static fn() => ProductSuggestionsQuery::forProducts(['a,b']),
            static fn() => ProductSuggestionsQuery::forProducts(['a'])->limit(13),
        ] as $i => $build) {
            try {
                $build();
                self::fail("Case $i was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
