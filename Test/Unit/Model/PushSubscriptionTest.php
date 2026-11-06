<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\PushSubscription;

class PushSubscriptionTest extends AbstractModelTestCase
{
    private function makeModel(): PushSubscription
    {
        return new PushSubscription($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
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

    public function testEndpointRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setEndpoint('https://fcm.googleapis.com/fcm/send/abc123');

        self::assertSame('https://fcm.googleapis.com/fcm/send/abc123', $model->getEndpoint());
    }

    public function testEndpointHashRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setEndpointHash('deadbeef');

        self::assertSame('deadbeef', $model->getEndpointHash());
    }

    public function testP256dhKeyRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setP256dhKey('BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM=');

        self::assertSame(
            'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM=',
            $model->getP256dhKey()
        );
    }

    public function testAuthKeyRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAuthKey('tBHItJI5svbpez7KI4CCXg==');

        self::assertSame('tBHItJI5svbpez7KI4CCXg==', $model->getAuthKey());
    }

    public function testCreatedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCreatedAt('2026-01-01 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getData('created_at'));
    }

    public function testLastSeenAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLastSeenAt('2026-01-02 00:00:00');

        self::assertSame('2026-01-02 00:00:00', $model->getData('last_seen_at'));
    }

    public function testBelongsToMatchesByCustomerId(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertTrue($model->belongsTo(42, ''));
    }

    public function testBelongsToMatchesByVisitorId(): void
    {
        $model = $this->makeModel();
        $model->setVisitorId('visitor-abc123');

        self::assertTrue($model->belongsTo(null, 'visitor-abc123'));
    }

    public function testBelongsToRejectsMismatchedCustomerId(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertFalse($model->belongsTo(99, ''));
    }

    public function testBelongsToRejectsEmptyVisitorIdEvenWhenRowHasNoVisitorIdEither(): void
    {
        $model = $this->makeModel();

        self::assertFalse($model->belongsTo(null, ''));
    }
}
