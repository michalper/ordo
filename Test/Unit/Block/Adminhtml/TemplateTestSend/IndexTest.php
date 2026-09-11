<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\TemplateTestSend;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\TemplateTestSend\Index;
use Ordo\Automation\Model\Config\Source\WhatsAppTemplateOptions;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-stubbing technique as Segment\AudienceSizeTest/Segment\OverlapTest -
 * Template's own constructor falls back to ObjectManager::getInstance()->get(...) for
 * jsonHelper/directoryHelper whenever a subclass (like this one) doesn't forward them explicitly.
 */
class IndexTest extends TestCase
{
    private WhatsAppTemplateOptions $whatsAppTemplateOptions;
    private UrlInterface $urlBuilder;
    private Index $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->whatsAppTemplateOptions = $this->createStub(WhatsAppTemplateOptions::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new Index($context, $this->whatsAppTemplateOptions);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetWhatsAppTemplateOptionsReturnsTheSourceModelsOptionArray(): void
    {
        $options = [['value' => 3, 'label' => 'Order Shipped']];
        $this->whatsAppTemplateOptions->method('toOptionArray')->willReturn($options);

        self::assertSame($options, $this->block->getWhatsAppTemplateOptions());
    }

    public function testGetSendUrlBuildsControllerUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturnMap([
            ['*/*/send', [], 'https://example.com/admin/ordo/templatetestsend/send/'],
        ]);

        self::assertSame(
            'https://example.com/admin/ordo/templatetestsend/send/',
            $this->block->getSendUrl()
        );
    }
}
