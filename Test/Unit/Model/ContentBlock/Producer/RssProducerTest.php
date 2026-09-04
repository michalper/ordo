<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock\Producer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\Producer\RssProducer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * RssProducer::__construct() only accepts a ResourceConnection — it has no Curl/RssFetcher
 * collaborator at all, so this reads-only-from-cache invariant is enforced structurally, not
 * just behaviourally: there is nothing to invoke an HTTP client with even if the code wanted to.
 */
class RssProducerTest extends TestCase
{
    public function testConstructorHasNoHttpClientCollaborator(): void
    {
        $reflection = new \ReflectionClass(RssProducer::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;

            self::assertStringNotContainsStringIgnoringCase('curl', $typeName);
            self::assertStringNotContainsStringIgnoringCase('rssfetcher', $typeName);
        }
    }

    private function makeBlock(): ContentBlock
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setData('entity_id', 9);

        return $block;
    }

    private function makeProducer(AdapterInterface $connection): RssProducer
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnMap([['ordo_content_block_rss_cache', 'ordo_content_block_rss_cache']]);

        return new RssProducer($resourceConnection);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRendersCachedHtmlFromTable(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('<table>cached</table>');

        $producer = $this->makeProducer($connection);

        self::assertSame('<table>cached</table>', $producer->render($this->makeBlock()));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsEmptyStringWhenNoCacheRowExists(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);

        $producer = $this->makeProducer($connection);

        self::assertSame('', $producer->render($this->makeBlock()));
    }

    public function testReturnsEmptyStringWhenBlockHasNoEntityId(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $producer = new RssProducer($resourceConnection);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);

        self::assertSame('', $producer->render($block));
    }
}
