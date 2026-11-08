<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl;

use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\OfferDataMapper;
use PHPUnit\Framework\TestCase;

class OfferDataMapperTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(2);
        $this->config->method('getOfferSelfExtensionDays')->willReturn(7);
    }

    public function testMapsAnExtendableSentOffer(): void
    {
        $offer = $this->createMock(OfferInterface::class);
        $offer->method('getEntityId')->willReturn(1);
        $offer->method('getReference')->willReturn('OFFER-1');
        $offer->method('getStatus')->willReturn(OfferInterface::STATUS_SENT);
        $offer->method('getTotal')->willReturn(99.5);
        $offer->method('getCurrencyCode')->willReturn('USD');
        $offer->method('getExpiresAt')->willReturn('2026-12-01 00:00:00');
        $offer->expects(self::once())->method('canSelfExtend')->with(2)->willReturn(true);

        self::assertSame(
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
            (new OfferDataMapper($this->config))->map($offer)
        );
    }

    public function testNeverCallsCanSelfExtendForAnOfferThatIsNotInSentStatus(): void
    {
        $offer = $this->createMock(OfferInterface::class);
        $offer->method('getEntityId')->willReturn(2);
        $offer->method('getReference')->willReturn('OFFER-2');
        $offer->method('getStatus')->willReturn(OfferInterface::STATUS_EXPIRED);
        $offer->method('getTotal')->willReturn(50.0);
        $offer->method('getCurrencyCode')->willReturn('USD');
        $offer->method('getExpiresAt')->willReturn('2026-01-01 00:00:00');
        $offer->expects(self::never())->method('canSelfExtend');

        $result = (new OfferDataMapper($this->config))->map($offer);

        self::assertFalse($result['can_self_extend']);
    }
}
