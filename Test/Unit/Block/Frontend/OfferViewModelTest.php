<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Frontend;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\Data\OfferSearchResultsInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Block\Frontend\OfferViewModel;
use Ordo\Automation\Helper\Config;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class OfferViewModelTest extends TestCase
{
    private CustomerSession $customerSession;
    private OfferRepositoryInterface $offerRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Config $config;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->offerRepository = $this->createMock(OfferRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->config = $this->createStub(Config::class);
    }

    private function makeViewModel(): OfferViewModel
    {
        return new OfferViewModel(
            $this->customerSession,
            $this->offerRepository,
            $this->searchCriteriaBuilder,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetOffersReturnsEmptyArrayWhenNoCustomerLoggedIn(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(null);
        $this->offerRepository->expects(self::never())->method('getList');

        self::assertSame([], $this->makeViewModel()->getOffers());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetOffersFiltersByCustomerId(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $searchCriteria = $this->createStub(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $offer = $this->createStub(OfferInterface::class);
        $searchResults = $this->createStub(OfferSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$offer]);

        $this->offerRepository->expects(self::once())->method('getList')->with($searchCriteria)
            ->willReturn($searchResults);

        self::assertSame([$offer], $this->makeViewModel()->getOffers());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCanSelfExtendReturnsFalseWhenOfferNotSent(): void
    {
        $offer = $this->createStub(OfferInterface::class);
        $offer->method('getStatus')->willReturn(OfferInterface::STATUS_ACCEPTED);

        self::assertFalse($this->makeViewModel()->canSelfExtend($offer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCanSelfExtendDelegatesToOfferWhenSent(): void
    {
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(3);

        $offer = $this->createMock(OfferInterface::class);
        $offer->method('getStatus')->willReturn(OfferInterface::STATUS_SENT);
        $offer->expects(self::once())->method('canSelfExtend')->with(3)->willReturn(true);

        self::assertTrue($this->makeViewModel()->canSelfExtend($offer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetMaxSelfExtensionsReturnsConfigValue(): void
    {
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(3);
        self::assertSame(3, $this->makeViewModel()->getMaxSelfExtensions());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetSelfExtensionDaysReturnsConfigValue(): void
    {
        $this->config->method('getOfferSelfExtensionDays')->willReturn(7);
        self::assertSame(7, $this->makeViewModel()->getSelfExtensionDays());
    }
}
