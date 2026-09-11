<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Block\Adminhtml\WhatsAppTemplate\BodyPreview;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-stubbing technique as Segment\AudienceSizeTest/Segment\OverlapTest -
 * Template's own constructor falls back to ObjectManager::getInstance()->get(...) for
 * jsonHelper/directoryHelper whenever a subclass (like BodyPreview) doesn't forward them
 * explicitly.
 */
class BodyPreviewTest extends TestCase
{
    private BodyPreview $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $context = $this->createStub(Context::class);

        $this->block = new BodyPreview($context);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetBodyMaxLengthMatchesMetasDocumentedLimit(): void
    {
        self::assertSame(1024, $this->block->getBodyMaxLength());
        self::assertSame(BodyPreview::BODY_MAX_LENGTH, $this->block->getBodyMaxLength());
    }
}
