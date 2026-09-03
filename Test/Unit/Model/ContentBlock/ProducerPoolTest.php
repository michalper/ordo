<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Ordo\Automation\Model\ContentBlock\Producer\ProducerInterface;
use Ordo\Automation\Model\ContentBlock\ProducerPool;
use PHPUnit\Framework\TestCase;

class ProducerPoolTest extends TestCase
{
    public function testGetReturnsRegisteredProducer(): void
    {
        $snippetProducer = $this->createStub(ProducerInterface::class);
        $rssProducer = $this->createStub(ProducerInterface::class);

        $pool = new ProducerPool([
            'snippet' => $snippetProducer,
            'rss' => $rssProducer,
        ]);

        self::assertSame($snippetProducer, $pool->get('snippet'));
        self::assertSame($rssProducer, $pool->get('rss'));
    }

    public function testGetReturnsNullForUnknownType(): void
    {
        $pool = new ProducerPool(['snippet' => $this->createStub(ProducerInterface::class)]);

        self::assertNull($pool->get('unknown_type'));
    }

    public function testGetReturnsNullWhenPoolIsEmpty(): void
    {
        $pool = new ProducerPool();

        self::assertNull($pool->get('snippet'));
    }

    public function testGetAvailableTypesReflectsInjectedMap(): void
    {
        $pool = new ProducerPool([
            'snippet' => $this->createStub(ProducerInterface::class),
            'rss' => $this->createStub(ProducerInterface::class),
            'product_feed' => $this->createStub(ProducerInterface::class),
        ]);

        self::assertSame(['snippet', 'rss', 'product_feed'], $pool->getAvailableTypes());
    }

    public function testGetAvailableTypesReturnsEmptyArrayWhenPoolIsEmpty(): void
    {
        $pool = new ProducerPool();

        self::assertSame([], $pool->getAvailableTypes());
    }
}
