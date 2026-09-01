<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Cron\ExpireOverdueOffers;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;
use Ordo\Automation\Model\ResourceModel\Offer\Collection;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ExpireOverdueOffersTest extends TestCase
{
    public function testExecuteMarksOffersExpired(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->expects(self::once())->method('setStatus')->with(OfferInterface::STATUS_EXPIRED);

        $collection = $this->createStub(Collection::class);
        $collection->method('addPastExpiryFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$offer]));

        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $offerResource = $this->createMock(OfferResource::class);
        $offerResource->expects(self::once())->method('save')->with($offer);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new ExpireOverdueOffers($collectionFactory, $offerResource, $logger))->execute();
    }

    public function testExecuteHandlesEmptyCollection(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addPastExpiryFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $offerResource = $this->createMock(OfferResource::class);
        $offerResource->expects(self::never())->method('save');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new ExpireOverdueOffers($collectionFactory, $offerResource, $logger))->execute();
    }
}
