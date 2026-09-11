<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Segment\Overlap;
use Ordo\Automation\Model\Config\Source\SegmentOptions;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-stubbing technique as AudienceSizeTest - Template's own constructor falls
 * back to ObjectManager::getInstance()->get(...) for jsonHelper/directoryHelper whenever a
 * subclass (like Overlap) doesn't forward them explicitly.
 */
class OverlapTest extends TestCase
{
    private SegmentOptions $segmentOptions;
    private UrlInterface $urlBuilder;
    private Overlap $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->segmentOptions = $this->createStub(SegmentOptions::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new Overlap($context, $this->segmentOptions);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetSegmentOptionsReturnsTheSourceModelsOptionArray(): void
    {
        $options = [['value' => 1, 'label' => 'VIP customers']];
        $this->segmentOptions->method('toOptionArray')->willReturn($options);

        self::assertSame($options, $this->block->getSegmentOptions());
    }

    public function testGetOverlapComputeUrlBuildsControllerUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturnMap([
            ['*/segment/overlapCompute', [], 'https://example.com/admin/ordo/segment/overlapCompute/'],
        ]);

        self::assertSame(
            'https://example.com/admin/ordo/segment/overlapCompute/',
            $this->block->getOverlapComputeUrl()
        );
    }
}
