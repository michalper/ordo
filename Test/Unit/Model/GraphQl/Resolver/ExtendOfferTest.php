<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\OfferManagementInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\OfferDataMapper;
use Ordo\Automation\Model\GraphQl\Resolver\ExtendOffer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ExtendOfferTest extends TestCase
{
    private OfferManagementInterface $offerManagement;
    private Config $config;

    protected function setUp(): void
    {
        $this->offerManagement = $this->createMock(OfferManagementInterface::class);
        $this->config = $this->createStub(Config::class);
    }

    private function resolve(array $args): mixed
    {
        $resolver = new ExtendOffer($this->offerManagement, new OfferDataMapper($this->config));

        return $resolver->resolve(
            $this->createStub(Field::class),
            $this->createStub(ContextInterface::class),
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    public function testExtendsAndReturnsTheFreshOffer(): void
    {
        $offer = $this->createMock(OfferInterface::class);
        $offer->method('getEntityId')->willReturn(7);
        $offer->method('getReference')->willReturn('OFFER-7');
        $offer->method('getStatus')->willReturn(OfferInterface::STATUS_SENT);
        $offer->method('getTotal')->willReturn(10.0);
        $offer->method('getCurrencyCode')->willReturn('EUR');
        $offer->method('getExpiresAt')->willReturn('2026-12-08 00:00:00');
        $offer->method('canSelfExtend')->with(2)->willReturn(false);

        $this->offerManagement->expects(self::once())->method('selfExtend')->with(7)->willReturn($offer);
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(2);
        $this->config->method('getOfferSelfExtensionDays')->willReturn(7);

        self::assertSame(
            [
                'ok' => true,
                'offer' => [
                    'entity_id' => 7,
                    'reference' => 'OFFER-7',
                    'status' => OfferInterface::STATUS_SENT,
                    'total' => 10.0,
                    'currency_code' => 'EUR',
                    'expires_at' => '2026-12-08 00:00:00',
                    'can_self_extend' => false,
                    'self_extension_days' => 7,
                ],
            ],
            $this->resolve(['offerId' => 7])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsOkFalseWithReasonWhenAlreadyAtTheExtensionCap(): void
    {
        $this->offerManagement->method('selfExtend')
            ->willThrowException(new LocalizedException(__('This offer has already been extended the maximum of 2 time(s).')));

        self::assertSame(
            ['ok' => false, 'reason' => 'This offer has already been extended the maximum of 2 time(s).'],
            $this->resolve(['offerId' => 7])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenOfferMissingOrNotOwned(): void
    {
        $this->offerManagement->method('selfExtend')
            ->willThrowException(new NoSuchEntityException(__('Offer with id "7" does not exist.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve(['offerId' => 7]);
    }

    public function testTreatsMissingOfferIdAsZero(): void
    {
        $this->offerManagement->expects(self::once())->method('selfExtend')->with(0)
            ->willThrowException(new NoSuchEntityException(__('Offer with id "0" does not exist.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve([]);
    }
}
