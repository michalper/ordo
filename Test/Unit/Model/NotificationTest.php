<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Notification;

class NotificationTest extends AbstractModelTestCase
{
    private function makeModel(): Notification
    {
        return new Notification($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCustomerId());

        $model->setCustomerId(42);
        self::assertSame(42, $model->getCustomerId());

        $model->setCustomerId(null);
        self::assertNull($model->getCustomerId());
    }

    public function testVisitorIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getVisitorId());

        $model->setVisitorId('v1');
        self::assertSame('v1', $model->getVisitorId());
    }

    public function testHeadlineRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setHeadline('Hello!');
        self::assertSame('Hello!', $model->getHeadline());
    }

    public function testBodyRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getBody());

        $model->setBody('Come back soon');
        self::assertSame('Come back soon', $model->getBody());
    }

    public function testCtaLabelRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCtaLabel());

        $model->setCtaLabel('Shop now');
        self::assertSame('Shop now', $model->getCtaLabel());
    }

    public function testCtaUrlRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCtaUrl());

        $model->setCtaUrl('https://example.test/sale');
        self::assertSame('https://example.test/sale', $model->getCtaUrl());
    }

    public function testReadAtRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getReadAt());

        $model->setReadAt('2026-01-01 00:00:00');
        self::assertSame('2026-01-01 00:00:00', $model->getReadAt());
    }

    public function testBelongsToMatchesOnCustomerId(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertTrue($model->belongsTo(42, ''));
        self::assertFalse($model->belongsTo(43, ''));
    }

    public function testBelongsToMatchesOnVisitorId(): void
    {
        $model = $this->makeModel();
        $model->setVisitorId('v1');

        self::assertTrue($model->belongsTo(null, 'v1'));
        self::assertFalse($model->belongsTo(null, 'someone-elses-visitor-id'));
    }

    public function testBelongsToNeverMatchesEmptyVisitorIdAgainstNullColumn(): void
    {
        $model = $this->makeModel();

        self::assertFalse($model->belongsTo(null, ''));
    }

    public function testBelongsToFallsBackToVisitorIdWhenCustomerIdDiffers(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(99);
        $model->setVisitorId('v1');

        self::assertTrue($model->belongsTo(42, 'v1'));
    }

    public function testExpiresAtCanBeSet(): void
    {
        $model = $this->makeModel();
        $model->setExpiresAt('2026-02-01 00:00:00');
        self::assertSame('2026-02-01 00:00:00', $model->getData(Notification::EXPIRES_AT));
    }
}
