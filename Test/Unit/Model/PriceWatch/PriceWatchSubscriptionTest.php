<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\PriceWatch;

use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Test\Unit\Model\AbstractModelTestCase;

class PriceWatchSubscriptionTest extends AbstractModelTestCase
{
    private function makeModel(): PriceWatchSubscription
    {
        return new PriceWatchSubscription($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getCustomerId());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertSame(42, $model->getCustomerId());
    }

    public function testCustomerIdCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);
        $model->setCustomerId(null);

        self::assertNull($model->getCustomerId());
    }

    public function testVisitorIdIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getVisitorId());
    }

    public function testVisitorIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setVisitorId('visitor-abc123');

        self::assertSame('visitor-abc123', $model->getVisitorId());
    }

    public function testVisitorIdCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setVisitorId('visitor-abc123');
        $model->setVisitorId(null);

        self::assertNull($model->getVisitorId());
    }

    public function testGuestEmailIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getGuestEmail());
    }

    public function testGuestEmailRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setGuestEmail('guest@example.com');

        self::assertSame('guest@example.com', $model->getGuestEmail());
    }

    public function testGuestEmailCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setGuestEmail('guest@example.com');
        $model->setGuestEmail(null);

        self::assertNull($model->getGuestEmail());
    }

    public function testProductIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setProductId(10);

        self::assertSame(10, $model->getProductId());
    }

    public function testWatchTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setWatchType(PriceWatchSubscription::WATCH_TYPE_PRICE_DROP);

        self::assertSame(PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, $model->getWatchType());
    }

    public function testLastKnownPriceIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getLastKnownPrice());
    }

    public function testLastKnownPriceRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLastKnownPrice(99.99);

        self::assertSame(99.99, $model->getLastKnownPrice());
    }

    public function testLastKnownPriceCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setLastKnownPrice(99.99);
        $model->setLastKnownPrice(null);

        self::assertNull($model->getLastKnownPrice());
    }

    public function testLastKnownInStockIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getLastKnownInStock());
    }

    public function testLastKnownInStockRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLastKnownInStock(true);

        self::assertTrue($model->getLastKnownInStock());
    }

    public function testLastKnownInStockCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setLastKnownInStock(false);
        $model->setLastKnownInStock(null);

        self::assertNull($model->getLastKnownInStock());
    }

    public function testCreatedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCreatedAt('2026-01-01 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getData('created_at'));
    }

    public function testNotifiedAtIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getNotifiedAt());
    }

    public function testNotifiedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setNotifiedAt('2026-01-02 00:00:00');

        self::assertSame('2026-01-02 00:00:00', $model->getNotifiedAt());
    }

    public function testNotifiedAtCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setNotifiedAt('2026-01-02 00:00:00');
        $model->setNotifiedAt(null);

        self::assertNull($model->getNotifiedAt());
    }
}
