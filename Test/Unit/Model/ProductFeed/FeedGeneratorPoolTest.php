<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Ordo\Automation\Model\ProductFeed\FeedGeneratorPool;
use PHPUnit\Framework\TestCase;

class FeedGeneratorPoolTest extends TestCase
{
    public function testGetAllReturnsRegisteredGenerators(): void
    {
        $google = $this->createStub(FeedGeneratorInterface::class);
        $meta = $this->createStub(FeedGeneratorInterface::class);

        $pool = new FeedGeneratorPool([
            'google_merchant' => $google,
            'meta_catalog' => $meta,
        ]);

        self::assertSame(
            ['google_merchant' => $google, 'meta_catalog' => $meta],
            $pool->getAll()
        );
    }

    public function testGetAllReturnsEmptyArrayWhenNoneRegistered(): void
    {
        self::assertSame([], (new FeedGeneratorPool())->getAll());
    }
}
