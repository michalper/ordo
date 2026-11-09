<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Campaign\Library;
use Ordo\Automation\Model\Campaign\TemplateLibrary;
use PHPUnit\Framework\TestCase;

class LibraryTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    /**
     * Magento\Backend\Block\Template::__construct() falls back to
     * ObjectManager::getInstance()->get(JsonHelper::class)/DirectoryHelper::class when neither is
     * passed explicitly - needs a real (stubbed) singleton set immediately before construction
     * (not in setUp() - something between setUp() and the test body resets it, confirmed
     * directly; every existing ObjectManager-stubbing test in this suite, e.g. FunnelReport/Grid/
     * CollectionTest, sets it inline right before `new X(...)` for the same reason), or it throws
     * "ObjectManager isn't initialized".
     */
    private function makeBlock(UrlInterface $urlBuilder, TemplateLibrary $templateLibrary): Library
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        return new Library($context, $templateLibrary);
    }

    public function testGetTemplatesDelegatesToTheTemplateLibrary(): void
    {
        $templates = [
            ['id' => 'welcome-series', 'name' => 'Welcome Series', 'description' => 'd', 'payload' => []],
        ];

        $templateLibrary = $this->createStub(TemplateLibrary::class);
        $templateLibrary->method('getTemplates')->willReturn($templates);

        $block = $this->makeBlock($this->createStub(UrlInterface::class), $templateLibrary);

        self::assertSame($templates, $block->getTemplates());
    }

    public function testGetImportUrlBuildsTheImportFromLibraryRoute(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('*/*/importfromlibrary')
            ->willReturn('https://admin.example.test/campaign/importfromlibrary');

        $block = $this->makeBlock($urlBuilder, $this->createStub(TemplateLibrary::class));

        self::assertSame('https://admin.example.test/campaign/importfromlibrary', $block->getImportUrl());
    }

    public function testGetBackUrlBuildsTheIndexRoute(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('*/*/index')
            ->willReturn('https://admin.example.test/campaign/index');

        $block = $this->makeBlock($urlBuilder, $this->createStub(TemplateLibrary::class));

        self::assertSame('https://admin.example.test/campaign/index', $block->getBackUrl());
    }
}
