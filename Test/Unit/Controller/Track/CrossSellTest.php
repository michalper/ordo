<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Controller\Track\CrossSell;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use Ordo\Automation\Model\Recommendation\ProductRecommender;
use Ordo\Automation\Model\Track\LastRealOrderIdReader;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CrossSellTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private OrderRepositoryInterface $orderRepository;
    private LastRealOrderIdReader $lastRealOrderIdReader;
    private ProductRecommender $productRecommender;
    private ProductRecommendationRenderer $productRecommendationRenderer;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->lastRealOrderIdReader = $this->createStub(LastRealOrderIdReader::class);
        $this->productRecommender = $this->createMock(ProductRecommender::class);
        $this->productRecommendationRenderer = $this->createMock(ProductRecommendationRenderer::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): CrossSell
    {
        return new CrossSell(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->orderRepository,
            $this->lastRealOrderIdReader,
            $this->productRecommender,
            $this->productRecommendationRenderer,
            $this->config
        );
    }

    private function makeOrder(int $customerId, string $incrementId): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyHtmlWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '']);
        $this->orderRepository->expects(self::never())->method('get');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyHtmlWhenOrderIdMissing(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(true);
        $this->request->expects(self::once())->method('getParam')->with('order_id')->willReturn(null);

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '']);
        $this->orderRepository->expects(self::never())->method('get');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyHtmlWhenOrderDoesNotExist(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(true);
        $this->request->expects(self::once())->method('getParam')->with('order_id')->willReturn('999');
        $this->orderRepository->expects(self::once())->method('get')->with(999)
            ->willThrowException(new NoSuchEntityException());

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '']);

        $controller->execute();
    }

    /**
     * Regression for the real IDOR-shaped gap docs/adr/0082 closes: order_id is a plain integer
     * on a public, unauthenticated endpoint - a valid, existing order that just doesn't belong
     * to the CURRENT checkout session must still be refused, not just a nonexistent one.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyHtmlWhenOrderDoesNotMatchTheCurrentSession(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(true);
        $this->request->expects(self::once())->method('getParam')->with('order_id')->willReturn('5');
        $this->orderRepository->expects(self::once())->method('get')->with(5)
            ->willReturn($this->makeOrder(7, '000000123'));
        $this->lastRealOrderIdReader->method('getLastRealOrderId')->willReturn('000000999');

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '']);
        $this->productRecommender->expects(self::never())->method('getRecommendedSkus');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRendersRecommendationsForTheOwnedOrder(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(true);
        $this->config->method('getCrossSellItemCount')->willReturn(4);
        $this->request->expects(self::once())->method('getParam')->with('order_id')->willReturn('5');
        $this->orderRepository->expects(self::once())->method('get')->with(5)
            ->willReturn($this->makeOrder(7, '000000123'));
        $this->lastRealOrderIdReader->method('getLastRealOrderId')->willReturn('000000123');
        $this->productRecommender->expects(self::once())->method('getRecommendedSkus')->with(7, 4)
            ->willReturn(['SKU-1', 'SKU-2']);
        $this->productRecommendationRenderer->expects(self::once())->method('renderHtml')
            ->with(['SKU-1', 'SKU-2'], 'You might also like')
            ->willReturn('<table>...</table>');

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '<table>...</table>']);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTreatsAGuestOrderAsCustomerIdZero(): void
    {
        $controller = $this->makeController();
        $this->config->method('isCrossSellWidgetEnabled')->willReturn(true);
        $this->config->method('getCrossSellItemCount')->willReturn(4);
        $this->request->expects(self::once())->method('getParam')->with('order_id')->willReturn('5');
        $this->orderRepository->expects(self::once())->method('get')->with(5)
            ->willReturn($this->makeOrder(0, '000000123'));
        $this->lastRealOrderIdReader->method('getLastRealOrderId')->willReturn('000000123');
        $this->productRecommender->expects(self::once())->method('getRecommendedSkus')->with(0, 4)
            ->willReturn([]);
        $this->productRecommendationRenderer->expects(self::once())->method('renderHtml')
            ->with([], 'You might also like')
            ->willReturn('');

        $this->jsonResult->expects(self::once())->method('setData')->with(['html' => '']);

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
