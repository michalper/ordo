<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Controller\Track\LowStock;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\ProductStockChecker;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class LowStockTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private ProductRepositoryInterface $productRepository;
    private ProductStockChecker $productStockChecker;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->productStockChecker = $this->createMock(ProductStockChecker::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): LowStock
    {
        return new LowStock(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->productRepository,
            $this->productStockChecker,
            $this->config
        );
    }

    private function makeProduct(int $id): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);
        $this->productRepository->expects(self::never())->method('get');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenSkuMissing(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->request->method('getParam')->with('sku')->willReturn(null);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);
        $this->productRepository->expects(self::never())->method('get');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenSkuDoesNotExist(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->request->method('getParam')->with('sku')->willReturn('MISSING-SKU');
        $this->productRepository->method('get')->with('MISSING-SKU')
            ->willThrowException(new NoSuchEntityException());

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);
        $this->productStockChecker->expects(self::never())->method('getQty');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenAboveThreshold(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5);
        $this->request->method('getParam')->with('sku')->willReturn('SKU-1');
        $this->productRepository->method('get')->with('SKU-1')->willReturn($this->makeProduct(42));
        $this->productStockChecker->method('getQty')->with(42)->willReturn(10.0);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenOutOfStock(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5);
        $this->request->method('getParam')->with('sku')->willReturn('SKU-1');
        $this->productRepository->method('get')->with('SKU-1')->willReturn($this->makeProduct(42));
        $this->productStockChecker->method('getQty')->with(42)->willReturn(0.0);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFormattedMessageWhenAtOrBelowThreshold(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5);
        $this->config->method('getLowStockMessageTemplate')->willReturn('Only {qty} left in stock');
        $this->request->method('getParam')->with('sku')->willReturn('SKU-1');
        $this->productRepository->method('get')->with('SKU-1')->willReturn($this->makeProduct(42));
        $this->productStockChecker->method('getQty')->with(42)->willReturn(3.0);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['message' => 'Only 3 left in stock']);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTruncatesAnOverlongSku(): void
    {
        $controller = $this->makeController();
        $this->config->method('isLowStockWidgetEnabled')->willReturn(true);
        $this->request->method('getParam')->with('sku')->willReturn(str_repeat('x', 100));

        $this->productRepository->expects(self::once())->method('get')->with(str_repeat('x', 64))
            ->willThrowException(new NoSuchEntityException());

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
