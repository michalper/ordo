<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Segment\BulkActions;
use Ordo\Automation\Model\Segment;
use PHPUnit\Framework\TestCase;

/**
 * Backend\Block\Template's own constructor (BulkActions's parent) falls back to
 * ObjectManager::getInstance()->get(...) for jsonHelper/directoryHelper whenever a subclass
 * doesn't forward them explicitly — which BulkActions doesn't, since it only exposes
 * (Context, Registry, array $data). Stubbing the global ObjectManager singleton for the
 * duration of these tests (and restoring it in tearDown) is the same technique
 * ResourceModel\Campaign\Grid\CollectionTest uses for the equivalent problem there.
 */
class BulkActionsTest extends TestCase
{
    private Registry $registry;
    private UrlInterface $urlBuilder;
    private BulkActions $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->registry = $this->createStub(Registry::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new BulkActions($context, $this->registry);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetSegmentReturnsNullWhenRegistryHasNoSegment(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertNull($this->block->getSegment());
    }

    public function testGetSegmentReturnsNullWhenRegistryHoldsSomethingElse(): void
    {
        $this->registry->method('registry')->willReturn(new \stdClass());

        self::assertNull($this->block->getSegment());
    }

    public function testGetSegmentReturnsTheRegisteredSegment(): void
    {
        $segment = $this->createStub(Segment::class);
        $this->registry->method('registry')->willReturn($segment);

        self::assertSame($segment, $this->block->getSegment());
    }

    public function testGetSegmentIdReturnsZeroWhenNoSegmentIsRegistered(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertSame(0, $this->block->getSegmentId());
    }

    public function testGetSegmentIdReturnsTheSegmentsEntityId(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->registry->method('registry')->willReturn($segment);

        self::assertSame(7, $this->block->getSegmentId());
    }

    public function testIsSegmentSavedIsFalseWithoutASegmentId(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertFalse($this->block->isSegmentSaved());
    }

    public function testIsSegmentSavedIsTrueWithASegmentId(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->registry->method('registry')->willReturn($segment);

        self::assertTrue($this->block->isSegmentSaved());
    }

    public function testGetFormActionBuildsTheBulkActionControllerUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/segment/bulkAction/');

        self::assertSame('https://example.com/admin/ordo/segment/bulkAction/', $this->block->getFormAction());
    }
}
