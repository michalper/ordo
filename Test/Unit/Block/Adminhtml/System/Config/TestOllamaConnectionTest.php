<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\System\Config\TestOllamaConnection;
use PHPUnit\Framework\TestCase;

/**
 * render()/_getElementHtml() are pure delegation to Magento's own Field/Template rendering
 * pipeline (real template resolution, real filesystem reads) - not meaningfully unit-testable
 * without a full app bootstrap, same reasoning every other Field/Template-based block in this
 * module's Test/Unit only ever exercises its own added logic (see Segment\AudienceSizeTest,
 * WhatsAppTemplate\BodyPreviewTest). getAjaxUrl() is the one piece of actual logic this class
 * adds, so it's the only thing tested here.
 *
 * Backend\Block\Template's own constructor (Field's parent) falls back to
 * ObjectManager::getInstance()->get(...) for jsonHelper/directoryHelper whenever a subclass
 * doesn't forward them explicitly - which TestOllamaConnection doesn't. Stubbing the global
 * ObjectManager singleton for the duration of this test (and restoring it in tearDown) is the
 * same technique AudienceSizeTest/BulkActionsTest use for the equivalent problem there.
 */
class TestOllamaConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetAjaxUrlBuildsTheTestConnectionControllerUrl(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([
            ['ordo/ai/testconnection', [], 'https://example.com/admin/ordo/ai/testconnection/'],
        ]);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $block = new TestOllamaConnection($context);

        self::assertSame(
            'https://example.com/admin/ordo/ai/testconnection/',
            $block->getAjaxUrl()
        );
    }
}
