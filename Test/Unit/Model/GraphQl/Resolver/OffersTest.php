<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\Data\OfferSearchResultsInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\OfferDataMapper;
use Ordo\Automation\Model\GraphQl\Resolver\Offers;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class OffersTest extends TestCase
{
    private OfferRepositoryInterface $offerRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Config $config;

    protected function setUp(): void
    {
        $this->offerRepository = $this->createMock(OfferRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->config = $this->createStub(Config::class);
    }

    private function makeResolver(): Offers
    {
        return new Offers(
            $this->offerRepository,
            $this->searchCriteriaBuilder,
            new CustomerIdResolver(),
            new OfferDataMapper($this->config)
        );
    }

    private function resolve(Offers $resolver, int $userId): mixed
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            null
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsEmptyArrayForGuest(): void
    {
        $resolver = $this->makeResolver();

        $this->offerRepository->expects(self::never())->method('getList');

        self::assertSame([], $this->resolve($resolver, 0));
    }

    public function testMapsEachOfferAndScopesSearchToTheCustomer(): void
    {
        $resolver = $this->makeResolver();

        $searchCriteria = $this->createStub(SearchCriteria::class);
        $this->searchCriteriaBuilder->expects(self::once())
            ->method('addFilter')
            ->with(OfferInterface::CUSTOMER_ID, 42)
            ->willReturnSelf();
        $this->searchCriteriaBuilder->expects(self::once())->method('create')->willReturn($searchCriteria);

        $sentOffer = $this->createStub(OfferInterface::class);
        $sentOffer->method('getEntityId')->willReturn(1);
        $sentOffer->method('getReference')->willReturn('OFFER-1');
        $sentOffer->method('getStatus')->willReturn(OfferInterface::STATUS_SENT);
        $sentOffer->method('getTotal')->willReturn(99.5);
        $sentOffer->method('getCurrencyCode')->willReturn('USD');
        $sentOffer->method('getExpiresAt')->willReturn('2026-12-01 00:00:00');
        $sentOffer->method('canSelfExtend')->willReturn(true);

        $expiredOffer = $this->createStub(OfferInterface::class);
        $expiredOffer->method('getEntityId')->willReturn(2);
        $expiredOffer->method('getReference')->willReturn('OFFER-2');
        $expiredOffer->method('getStatus')->willReturn(OfferInterface::STATUS_EXPIRED);
        $expiredOffer->method('getTotal')->willReturn(50.0);
        $expiredOffer->method('getCurrencyCode')->willReturn('USD');
        $expiredOffer->method('getExpiresAt')->willReturn('2026-01-01 00:00:00');

        $searchResults = $this->createStub(OfferSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$sentOffer, $expiredOffer]);
        $this->offerRepository->expects(self::once())
            ->method('getList')
            ->with($searchCriteria)
            ->willReturn($searchResults);

        $this->config->method('getOfferMaxSelfExtensions')->willReturn(2);
        $this->config->method('getOfferSelfExtensionDays')->willReturn(7);

        self::assertSame(
            [
                [
                    'entity_id' => 1,
                    'reference' => 'OFFER-1',
                    'status' => OfferInterface::STATUS_SENT,
                    'total' => 99.5,
                    'currency_code' => 'USD',
                    'expires_at' => '2026-12-01 00:00:00',
                    'can_self_extend' => true,
                    'self_extension_days' => 7,
                ],
                [
                    'entity_id' => 2,
                    'reference' => 'OFFER-2',
                    'status' => OfferInterface::STATUS_EXPIRED,
                    'total' => 50.0,
                    'currency_code' => 'USD',
                    'expires_at' => '2026-01-01 00:00:00',
                    'can_self_extend' => false,
                    'self_extension_days' => 7,
                ],
            ],
            $this->resolve($resolver, 42)
        );
    }
}
