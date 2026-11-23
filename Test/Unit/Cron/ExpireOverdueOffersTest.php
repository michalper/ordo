<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\ExpireOverdueOffers;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExpireOverdueOffersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteMarksOffersExpired(): void
    {
        $offerResource = $this->createMock(OfferResource::class);
        $offerResource->expects(self::once())->method('expireOverdue')->with(date('Y-m-d'))
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('marked 3 offers as expired'));

        (new ExpireOverdueOffers($offerResource, $this->makeCronRunLogger($logger)))->execute();
    }

    public function testExecuteHandlesNoneOverdue(): void
    {
        $offerResource = $this->createStub(OfferResource::class);
        $offerResource->method('expireOverdue')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('marked 0 offers as expired'));

        (new ExpireOverdueOffers($offerResource, $this->makeCronRunLogger($logger)))->execute();
    }
}
