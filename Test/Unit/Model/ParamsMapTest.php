<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ParamsMap;
use PHPUnit\Framework\TestCase;

class ParamsMapTest extends TestCase
{
    public function testFromJsonDecodesAMap(): void
    {
        self::assertSame(['tag' => 'vip'], ParamsMap::fromJson('{"tag":"vip"}'));
    }

    public function testFromJsonReturnsEmptyForEmptyOrNonMapJson(): void
    {
        self::assertSame([], ParamsMap::fromJson(''));
        self::assertSame([], ParamsMap::fromJson('not-json'));
        self::assertSame([], ParamsMap::fromJson('"a-bare-string"'));
    }

    public function testFromJsonDropsNonStringKeys(): void
    {
        // A JSON list decodes to integer keys, and PHP turns a numeric JSON object key into an
        // int key too - neither belongs in a params map.
        self::assertSame([], ParamsMap::fromJson('["a","b"]'));
        self::assertSame(['tag' => 'vip'], ParamsMap::fromJson('{"tag":"vip","0":"smuggled"}'));
    }

    public function testStringKeyedReturnsEmptyForANonArrayValue(): void
    {
        self::assertSame([], ParamsMap::stringKeyed('not-an-array'));
        self::assertSame([], ParamsMap::stringKeyed(null));
    }

    public function testStringKeyedKeepsOnlyStringKeys(): void
    {
        self::assertSame(['a' => 1], ParamsMap::stringKeyed([0 => 'x', 'a' => 1]));
    }
}
