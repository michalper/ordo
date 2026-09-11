<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ReorderCycle;

use Magento\Backend\Model\Session\Quote as QuoteSession;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\AdminOrder\Create as OrderCreate;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycle\ReorderCartBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ReorderCartBuilderTest extends TestCase
{
    private ProductRepositoryInterface $productRepository;
    private QuoteSession $quoteSession;
    private OrderCreate $orderCreate;
    private ReorderCartBuilder $builder;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    private array $quoteSessionCalls;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);

        // Quote\Session's setCustomerId()/setStoreId() are magic (SessionManager::__call()
        // proxies to internal storage, not a real declared method) - PHPUnit can only mock a
        // real method, so __call() itself (which IS real/declared) is the mockable seam;
        // calling ->setCustomerId(...) on this double still resolves to __call() exactly like
        // it would on the real class.
        $this->quoteSessionCalls = [];
        $this->quoteSession = $this->getMockBuilder(QuoteSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $this->quoteSession->method('__call')->willReturnCallback(
            function (string $method, array $args) {
                $this->quoteSessionCalls[] = [$method, $args];
                return $this->quoteSession;
            }
        );

        $this->orderCreate = $this->createMock(OrderCreate::class);

        $this->builder = new ReorderCartBuilder(
            $this->productRepository,
            $this->quoteSession,
            $this->orderCreate
        );
    }

    private function makeCycle(string $sku): ReorderCycle
    {
        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getSku')->willReturn($sku);

        return $cycle;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildSetsCustomerAndStoreOnTheSessionBeforeAddingTheProduct(): void
    {
        $cycle = $this->makeCycle('SKU-1');
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(42);
        $this->productRepository->method('get')->with('SKU-1')->willReturn($product);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getStoreId')->willReturn(2);

        $this->orderCreate->expects(self::once())->method('addProduct')->with(42, ['qty' => 1]);

        $this->builder->build($cycle, $customer);

        self::assertSame(
            [['setCustomerId', [7]], ['setStoreId', [2]]],
            $this->quoteSessionCalls
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildPropagatesNoSuchEntityExceptionWhenTheSkuNoLongerResolves(): void
    {
        $cycle = $this->makeCycle('SKU-GONE');
        $this->productRepository->method('get')->willThrowException(
            new NoSuchEntityException(__('no such product'))
        );

        $customer = $this->createStub(CustomerInterface::class);

        $this->orderCreate->expects(self::never())->method('addProduct');

        $this->expectException(NoSuchEntityException::class);
        $this->builder->build($cycle, $customer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildPropagatesALocalizedExceptionFromAddProduct(): void
    {
        $cycle = $this->makeCycle('SKU-1');
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(42);
        $this->productRepository->method('get')->willReturn($product);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(2);

        $this->orderCreate->method('addProduct')->willThrowException(
            new LocalizedException(__('out of stock'))
        );

        $this->expectException(LocalizedException::class);
        $this->builder->build($cycle, $customer);
    }
}
