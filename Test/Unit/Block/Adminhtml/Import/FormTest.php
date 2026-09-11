<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Import;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Import\Form;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-stubbing technique as Block\Adminhtml\Segment\OverlapTest - Template's own
 * constructor falls back to ObjectManager::getInstance()->get(...) for jsonHelper/
 * directoryHelper whenever a subclass (like Form) doesn't forward them explicitly.
 */
class FormTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    private function makeBlock(array $data): Form
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            fn (string $path): string => 'https://example.test/admin/' . $path
        );

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        return new Form($context, $data);
    }

    public function testGetImportUrlUsesTheConfiguredPath(): void
    {
        $block = $this->makeBlock(['import_url_path' => 'ordo/campaign/import']);

        self::assertSame('https://example.test/admin/ordo/campaign/import', $block->getImportUrl());
    }

    public function testGetBackUrlUsesTheConfiguredPath(): void
    {
        $block = $this->makeBlock(['back_url_path' => 'ordo/campaign/index']);

        self::assertSame('https://example.test/admin/ordo/campaign/index', $block->getBackUrl());
    }

    public function testGetEntityLabelReturnsTheConfiguredLabel(): void
    {
        $block = $this->makeBlock(['entity_label' => 'Campaign']);

        self::assertSame('Campaign', $block->getEntityLabel());
    }

    public function testGetEntityLabelFallsBackToEmptyStringWhenUnconfigured(): void
    {
        $block = $this->makeBlock([]);

        self::assertSame('', $block->getEntityLabel());
    }
}
