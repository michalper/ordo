<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Customer360;

use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Customer360\Index;
use Ordo\Automation\Model\Customer360\Customer360Snapshot;
use Ordo\Automation\Model\Customer360\Customer360SnapshotBuilder;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private Registry $registry;
    private CustomerRepositoryInterface $customerRepository;
    private Customer360SnapshotBuilder $snapshotBuilder;
    private UrlInterface $urlBuilder;
    private PricingHelper $pricingHelper;
    private Index $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->registry = $this->createStub(Registry::class);
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->snapshotBuilder = $this->createStub(Customer360SnapshotBuilder::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);
        $this->pricingHelper = $this->createStub(PricingHelper::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new Index(
            $context,
            $this->registry,
            $this->customerRepository,
            $this->snapshotBuilder,
            $this->pricingHelper
        );
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testIsCustomerResolvedIsFalseWithoutACustomerId(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertFalse($this->block->isCustomerResolved());
    }

    public function testIsCustomerResolvedIsTrueWithACustomerId(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_customer_360_customer_id', 42]]);

        self::assertTrue($this->block->isCustomerResolved());
    }

    public function testGetCustomerEmailReturnsRegisteredValue(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_customer_360_customer_email', 'jan@example.com']]);

        self::assertSame('jan@example.com', $this->block->getCustomerEmail());
    }

    public function testGetSnapshotReturnsNullWhenNoCustomerRegistered(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertNull($this->block->getSnapshot());
    }

    public function testGetSnapshotReturnsNullWhenCustomerNoLongerExists(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_customer_360_customer_id', 42]]);
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));

        self::assertNull($this->block->getSnapshot());
    }

    public function testGetSnapshotBuildsAndCachesTheSnapshot(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_customer_360_customer_id', 42]]);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);

        $snapshot = new Customer360Snapshot(
            customerId: 42,
            email: 'jan@example.com',
            name: 'Jan Kowalski',
            leadScore: 10,
            loyaltyTier: 'Silver',
            recencyDays: 5,
            orderFrequency: 3,
            monetaryTotal: 199.99,
            rfmScoreLabel: '4-3-3',
            tags: ['vip'],
            npsScore: 9,
            matchingSegmentNames: ['High spenders'],
            orderCount: 3,
            orderTotal: 199.99
        );

        $builder = $this->createMock(Customer360SnapshotBuilder::class);
        $builder->expects(self::once())->method('build')->with($customer)->willReturn($snapshot);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);
        $block = new Index($context, $this->registry, $this->customerRepository, $builder, $this->pricingHelper);

        self::assertSame($snapshot, $block->getSnapshot());
        // Second call must not rebuild - the mock's expects(once()) above enforces this.
        self::assertSame($snapshot, $block->getSnapshot());
    }

    public function testGetSearchFormActionBuildsIndexUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/customer360/index/');

        self::assertSame(
            'https://example.com/admin/ordo/customer360/index/',
            $this->block->getSearchFormAction()
        );
    }

    public function testFormatCurrencyDelegatesToPricingHelper(): void
    {
        $this->pricingHelper->method('currency')->willReturn('$199.99');

        self::assertSame('$199.99', $this->block->formatCurrency(199.99));
    }
}
