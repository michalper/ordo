<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\PendingPopup;

class PendingPopupTest extends AbstractModelTestCase
{
    private function makeModel(): PendingPopup
    {
        return new PendingPopup($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdDefaultsToNull(): void
    {
        self::assertNull($this->makeModel()->getCustomerId());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertSame(42, $model->getCustomerId());
    }

    public function testVisitorIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setVisitorId('v1');

        self::assertSame('v1', $model->getVisitorId());
    }

    public function testHeadlineRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setHeadline('Hello!');

        self::assertSame('Hello!', $model->getHeadline());
    }

    public function testBodyDefaultsToNull(): void
    {
        self::assertNull($this->makeModel()->getBody());
    }

    public function testBodyRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setBody('Limited time offer.');

        self::assertSame('Limited time offer.', $model->getBody());
    }

    public function testCtaLabelAndUrlRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCtaLabel('Shop now');
        $model->setCtaUrl('https://example.test/sale');

        self::assertSame('Shop now', $model->getCtaLabel());
        self::assertSame('https://example.test/sale', $model->getCtaUrl());
    }

    public function testDeliveredAtDefaultsToNull(): void
    {
        self::assertNull($this->makeModel()->getDeliveredAt());
    }

    public function testDeliveredAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setDeliveredAt('2026-01-01 12:00:00');

        self::assertSame('2026-01-01 12:00:00', $model->getDeliveredAt());
    }

    public function testSetExpiresAt(): void
    {
        $model = $this->makeModel();
        $model->setExpiresAt('2026-02-01 12:00:00');

        self::assertSame('2026-02-01 12:00:00', $model->getData(PendingPopup::EXPIRES_AT));
    }
}
