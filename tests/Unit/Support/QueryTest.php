<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Support;

use Instafind\Sdk\Support\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testListsRepeatTheKeyWithBrackets(): void
    {
        self::assertSame('id=abc&brand[]=A%20B&brand[]=C', Query::build(['id' => 'abc', 'brand' => ['A B', 'C']]));
    }

    public function testMapsNameTheirKeys(): void
    {
        self::assertSame('price[min]=1&price[max]=9', Query::build(['price' => ['min' => 1, 'max' => 9]]));
    }

    public function testNullIsLeftOutAndBooleansAreDigits(): void
    {
        self::assertSame('a=1&c=0', Query::build(['a' => true, 'b' => null, 'c' => false]));
    }

    public function testObjectsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Query::build(['a' => new \stdClass()]);
    }
}
