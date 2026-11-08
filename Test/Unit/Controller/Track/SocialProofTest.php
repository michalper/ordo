<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Track\SocialProof;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\ProductViewCounter;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SocialProofTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private ProductViewCounter $productViewCounter;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->productViewCounter = $this->createMock(ProductViewCounter::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): SocialProof
    {
        return new SocialProof(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->productViewCounter,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isSocialProofEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);
        $this->productViewCounter->expects(self::never())->method('countViewsToday');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenSkuMissing(): void
    {
        $controller = $this->makeController();
        $this->config->method('isSocialProofEnabled')->willReturn(true);
        $this->request->method('getParam')->with('sku')->willReturn(null);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);
        $this->productViewCounter->expects(self::never())->method('countViewsToday');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullMessageWhenBelowMinimumViews(): void
    {
        $controller = $this->makeController();
        $this->config->method('isSocialProofEnabled')->willReturn(true);
        $this->config->method('getSocialProofMinimumViews')->willReturn(3);
        $this->request->method('getParam')->with('sku')->willReturn('SKU-1');
        $this->productViewCounter->method('countViewsToday')->with('SKU-1')->willReturn(2);

        $this->jsonResult->expects(self::once())->method('setData')->with(['message' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFormattedMessageWhenAtOrAboveMinimumViews(): void
    {
        $controller = $this->makeController();
        $this->config->method('isSocialProofEnabled')->willReturn(true);
        $this->config->method('getSocialProofMinimumViews')->willReturn(3);
        $this->config->method('getSocialProofMessageTemplate')->willReturn('{count} people viewed this today');
        $this->request->method('getParam')->with('sku')->willReturn('SKU-1');
        $this->productViewCounter->method('countViewsToday')->with('SKU-1')->willReturn(12);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['message' => '12 people viewed this today']);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTruncatesAnOverlongSku(): void
    {
        $controller = $this->makeController();
        $this->config->method('isSocialProofEnabled')->willReturn(true);
        $this->config->method('getSocialProofMinimumViews')->willReturn(3);
        $this->request->method('getParam')->with('sku')->willReturn(str_repeat('x', 100));

        $this->productViewCounter->expects(self::once())->method('countViewsToday')->with(str_repeat('x', 64))
            ->willReturn(0);

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
