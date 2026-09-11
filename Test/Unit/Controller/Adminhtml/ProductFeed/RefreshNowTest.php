<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ProductFeed;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Controller\Adminhtml\ProductFeed\RefreshNow;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RefreshNowTest extends AbstractAdminActionTestCase
{
    private function makeStoreManager(): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('Main Website Store');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        return $storeManager;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteGeneratesWritesSuccessAndRedirects(): void
    {
        $context = $this->makeContext();

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('ordo/dashboard/index')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $generator = $this->createMock(GoogleMerchantFeedGenerator::class);
        $generator->method('generate')->willReturn(['xml' => '<rss></rss>', 'productCount' => 7]);

        $cacheWriter = $this->createMock(ProductFeedCacheWriter::class);
        $cacheWriter->expects(self::once())->method('writeSuccess')->with(1, '<rss></rss>', 7);
        $cacheWriter->expects(self::never())->method('writeError');

        $config = $this->createStub(Config::class);
        $config->method('isShoppingFeedEnabled')->willReturn(true);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $controller = new RefreshNow($context, $generator, $cacheWriter, $config, $this->makeStoreManager());
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWritesErrorAndRedirectsWhenGenerationThrows(): void
    {
        $context = $this->makeContext();

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $generator = $this->createMock(GoogleMerchantFeedGenerator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('catalog error'));

        $cacheWriter = $this->createMock(ProductFeedCacheWriter::class);
        $cacheWriter->expects(self::once())->method('writeError')->with(1, 'catalog error');
        $cacheWriter->expects(self::never())->method('writeSuccess');

        $config = $this->createStub(Config::class);
        $config->method('isShoppingFeedEnabled')->willReturn(true);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new RefreshNow($context, $generator, $cacheWriter, $config, $this->makeStoreManager());
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsStoreWhenFeedDisabled(): void
    {
        $context = $this->makeContext();

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $generator = $this->createMock(GoogleMerchantFeedGenerator::class);
        $generator->expects(self::never())->method('generate');

        $cacheWriter = $this->createMock(ProductFeedCacheWriter::class);

        $config = $this->createStub(Config::class);
        $config->method('isShoppingFeedEnabled')->willReturn(false);

        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $controller = new RefreshNow($context, $generator, $cacheWriter, $config, $this->makeStoreManager());
        self::assertSame($redirect, $controller->execute());
    }
}
