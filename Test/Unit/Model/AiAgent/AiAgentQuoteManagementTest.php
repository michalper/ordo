<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteItem;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteLine;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteLineFactory;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteManagement;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteResult;
use Ordo\Automation\Model\AiAgent\AiAgentQuoteResultFactory;
use Ordo\Automation\Test\Unit\QuoteAddressRateTestDouble;
use Ordo\Automation\Test\Unit\QuoteAddressTestDouble;
use Ordo\Automation\Test\Unit\QuoteItemTestDouble;
use Ordo\Automation\Test\Unit\QuoteTestDouble;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AiAgentQuoteManagementTest extends TestCase
{
    private QuoteFactory $quoteFactory;
    private StoreManagerInterface $storeManager;
    private ProductRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $productRepository;
    private Config $config;
    private AiAgentQuoteManagement $management;
    private QuoteAddressTestDouble $address;

    protected function setUp(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getAiAgentEstimatedDeliveryDays')->willReturn(5);

        $this->address = new QuoteAddressTestDouble();

        $this->quoteFactory = $this->createStub(QuoteFactory::class);

        $lineFactory = $this->createStub(AiAgentQuoteLineFactory::class);
        $lineFactory->method('create')->willReturnCallback(static fn () => new AiAgentQuoteLine());
        $resultFactory = $this->createStub(AiAgentQuoteResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(static fn () => new AiAgentQuoteResult());

        $this->management = new AiAgentQuoteManagement(
            $this->quoteFactory,
            $this->storeManager,
            $this->productRepository,
            $lineFactory,
            $resultFactory,
            $this->config
        );
    }

    /**
     * @param array<string, string|bool> $addProductResults sku => 'ok'|'error'|'missing'
     */
    private function makeQuote(array $addProductResults): QuoteTestDouble
    {
        $quote = $this->getMockBuilder(QuoteTestDouble::class)
            ->onlyMethods(['setStore', 'getShippingAddress', 'collectTotals', 'addProduct'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($this->address);
        $quote->method('addProduct')->willReturnCallback(function (Product $product, float $qty) use ($addProductResults) {
            $sku = $product->getSku();
            $result = $addProductResults[$sku] ?? 'ok';
            if ($result === 'error') {
                return 'Could not add product.';
            }
            return new QuoteItemTestDouble(10.0, 10.0 * $qty);
        });

        $this->quoteFactory->method('create')->willReturn($quote);

        return $quote;
    }

    private function makeProduct(string $sku): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        return $product;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetQuoteThrowsWhenNoSkusMatch(): void
    {
        $this->makeQuote([]);
        $this->productRepository->method('get')->willThrowException(
            new NoSuchEntityException(__('not found'))
        );

        $this->expectException(InputException::class);

        $this->management->getQuote(
            [(new AiAgentQuoteItem())->setSku('MISSING')->setQty(1.0)],
            'US',
            '10001'
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetQuoteSkipsUnmatchedSkusAndReportsThem(): void
    {
        $this->makeQuote(['SKU-BAD' => 'error']);
        $this->productRepository->method('get')->willReturnMap([
            ['SKU-OK', $this->makeProduct('SKU-OK')],
            ['SKU-BAD', $this->makeProduct('SKU-BAD')],
        ]);
        $this->address->setTestSubtotal(10.0)
            ->setTestDiscountAmount(0.0)
            ->setTestShippingAmount(0.0)
            ->setTestGrandTotal(10.0)
            ->setTestRates([]);

        $result = $this->management->getQuote(
            [
                (new AiAgentQuoteItem())->setSku('SKU-OK')->setQty(1.0),
                (new AiAgentQuoteItem())->setSku('SKU-BAD')->setQty(1.0),
            ],
            'US',
            '10001'
        );

        self::assertCount(1, $result->getLines());
        self::assertSame('SKU-OK', $result->getLines()[0]->getSku());
        self::assertSame(['SKU-BAD'], $result->getUnmatchedSkus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetQuoteSkipsSkuThatThrowsNoSuchEntityException(): void
    {
        $this->makeQuote([]);
        $this->productRepository->method('get')->willReturnCallback(function (string $sku) {
            if ($sku === 'MISSING') {
                throw new NoSuchEntityException(__('not found'));
            }
            return $this->makeProduct($sku);
        });
        $this->address->setTestSubtotal(10.0)
            ->setTestDiscountAmount(0.0)
            ->setTestShippingAmount(0.0)
            ->setTestGrandTotal(10.0)
            ->setTestRates([]);

        $result = $this->management->getQuote(
            [
                (new AiAgentQuoteItem())->setSku('SKU-OK')->setQty(1.0),
                (new AiAgentQuoteItem())->setSku('MISSING')->setQty(1.0),
            ],
            'US',
            '10001'
        );

        self::assertSame(['MISSING'], $result->getUnmatchedSkus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetQuoteSelectsTheCheapestShippingRate(): void
    {
        $this->makeQuote([]);
        $this->productRepository->method('get')->willReturn($this->makeProduct('SKU-OK'));
        $this->address->setTestSubtotal(100.0)
            ->setTestDiscountAmount(10.0)
            ->setTestShippingAmount(7.5)
            ->setTestGrandTotal(97.5)
            ->setTestRates([
                new QuoteAddressRateTestDouble('flatrate_flatrate', 12.0),
                new QuoteAddressRateTestDouble('tablerate_bestway', 7.5),
            ]);

        $result = $this->management->getQuote(
            [(new AiAgentQuoteItem())->setSku('SKU-OK')->setQty(2.0)],
            'US',
            '10001',
            'CA'
        );

        self::assertSame('USD', $result->getCurrency());
        self::assertSame(100.0, $result->getSubtotal());
        self::assertSame(10.0, $result->getDiscountAmount());
        self::assertSame(7.5, $result->getShippingAmount());
        self::assertSame(97.5, $result->getGrandTotal());
        self::assertSame(5, $result->getEstimatedDeliveryDays());
        self::assertSame([], $result->getUnmatchedSkus());
        self::assertCount(1, $result->getLines());
        self::assertSame(20.0, $result->getLines()[0]->getRowTotal());
    }
}
