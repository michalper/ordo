<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Offer;

class OfferTest extends AbstractModelTestCase
{
    private function makeModel(): Offer
    {
        return new Offer($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setEntityId('7');
        self::assertSame(7, $model->getEntityId());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(3);
        self::assertSame(3, $model->getCustomerId());
    }

    public function testReferenceRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setReference('OFR-001');
        self::assertSame('OFR-001', $model->getReference());
    }

    public function testStatusRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setStatus('sent');
        self::assertSame('sent', $model->getStatus());
    }

    public function testTotalRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTotal(199.99);
        self::assertSame(199.99, $model->getTotal());
    }

    public function testCurrencyCodeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCurrencyCode('PLN');
        self::assertSame('PLN', $model->getCurrencyCode());
    }

    public function testExpiresAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setExpiresAt('2026-02-01 00:00:00');
        self::assertSame('2026-02-01 00:00:00', $model->getExpiresAt());
    }

    public function testExtensionCountRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertSame(0, $model->getExtensionCount());

        $model->setExtensionCount(2);
        self::assertSame(2, $model->getExtensionCount());
    }

    public function testTimestampsReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('created_at', '2026-01-01 00:00:00');
        $model->setData('updated_at', '2026-01-02 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getCreatedAt());
        self::assertSame('2026-01-02 00:00:00', $model->getUpdatedAt());
    }

    public function testCanSelfExtendWhenBelowLimit(): void
    {
        $model = $this->makeModel();
        $model->setExtensionCount(1);

        self::assertTrue($model->canSelfExtend(2));
    }

    public function testCannotSelfExtendWhenAtLimit(): void
    {
        $model = $this->makeModel();
        $model->setExtensionCount(2);

        self::assertFalse($model->canSelfExtend(2));
    }
}
