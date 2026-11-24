<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\ExpireOverdueOffers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExpireOverdueOffersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(false);

        $offerResource = $this->createMock(OfferResource::class);
        $offerResource->expects(self::never())->method('expireOverdue');

        (new ExpireOverdueOffers($config, $offerResource, $this->makeCronRunLogger($this->createStub(LoggerInterface::class))))
            ->execute();
    }

    public function testExecuteMarksOffersExpired(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(true);

        $offerResource = $this->createMock(OfferResource::class);
        $offerResource->expects(self::once())->method('expireOverdue')->with(date('Y-m-d'))
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('marked 3 offers as expired'));

        (new ExpireOverdueOffers($config, $offerResource, $this->makeCronRunLogger($logger)))->execute();
    }

    public function testExecuteHandlesNoneOverdue(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(true);

        $offerResource = $this->createStub(OfferResource::class);
        $offerResource->method('expireOverdue')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('marked 0 offers as expired'));

        (new ExpireOverdueOffers($config, $offerResource, $this->makeCronRunLogger($logger)))->execute();
    }
}
