<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Segment\AudienceSize;
use Ordo\Automation\Model\Segment;
use PHPUnit\Framework\TestCase;

/**
 * Backend\Block\Template's own constructor (AudienceSize's parent) falls back to
 * ObjectManager::getInstance()->get(...) for jsonHelper/directoryHelper whenever a subclass
 * doesn't forward them explicitly - which AudienceSize doesn't, since it only exposes
 * (Context, Registry, array $data). Stubbing the global ObjectManager singleton for the
 * duration of these tests (and restoring it in tearDown) is the same technique
 * Segment\BulkActionsTest uses for the equivalent problem there.
 */
class AudienceSizeTest extends TestCase
{
    private Registry $registry;
    private UrlInterface $urlBuilder;
    private AudienceSize $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->registry = $this->createStub(Registry::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new AudienceSize($context, $this->registry);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetSegmentReturnsNullWhenRegistryHoldsNoSegment(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertNull($this->block->getSegment());
        self::assertSame(0, $this->block->getSegmentId());
        self::assertFalse($this->block->isSegmentSaved());
    }

    public function testGetSegmentReturnsNullWhenRegistryHoldsSomethingElse(): void
    {
        $this->registry->method('registry')->willReturn(new \stdClass());

        self::assertNull($this->block->getSegment());
        self::assertSame(0, $this->block->getSegmentId());
        self::assertFalse($this->block->isSegmentSaved());
    }

    public function testGetSegmentReturnsTheSavedSegmentFromRegistry(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->registry->method('registry')->willReturn($segment);

        self::assertSame($segment, $this->block->getSegment());
        self::assertSame(7, $this->block->getSegmentId());
        self::assertTrue($this->block->isSegmentSaved());
    }

    public function testGetAudienceSizeUrlBuildsControllerUrl(): void
    {
        $this->registry->method('registry')->willReturn(null);
        $this->urlBuilder->method('getUrl')->willReturnMap([
            ['*/segment/audienceSize', [], 'https://example.com/admin/ordo/segment/audienceSize/'],
        ]);

        self::assertSame(
            'https://example.com/admin/ordo/segment/audienceSize/',
            $this->block->getAudienceSizeUrl()
        );
    }
}
