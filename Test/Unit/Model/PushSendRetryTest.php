<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\PushSendRetry;

class PushSendRetryTest extends AbstractModelTestCase
{
    private function makeModel(): PushSendRetry
    {
        return new PushSendRetry($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testSubscriptionIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSubscriptionId(9);

        self::assertSame(9, $model->getSubscriptionId());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);

        self::assertSame(42, $model->getCustomerId());
    }

    public function testCampaignIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCampaignId(7);

        self::assertSame(7, $model->getCampaignId());
    }

    public function testCampaignIdDefaultsToNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getCampaignId());
    }

    public function testVariantRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setVariant('b');

        self::assertSame('b', $model->getVariant());
    }

    public function testVariantDefaultsToNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getVariant());
    }

    public function testPayloadRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setPayload('{"title":"Hi"}');

        self::assertSame('{"title":"Hi"}', $model->getPayload());
    }

    public function testAttemptsRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAttempts(2);

        self::assertSame(2, $model->getAttempts());
    }

    public function testLastErrorRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLastError('push service down');

        self::assertSame('push service down', $model->getLastError());
    }

    public function testLastErrorDefaultsToNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getLastError());
    }

    public function testNextRetryAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setNextRetryAt('2026-01-01 00:05:00');

        self::assertSame('2026-01-01 00:05:00', $model->getNextRetryAt());
    }
}
