<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignActionRetry;

class CampaignActionRetryTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignActionRetry
    {
        return new CampaignActionRetry($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCampaignIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCampaignId(3);

        self::assertSame(3, $model->getCampaignId());
    }

    public function testResumeActionIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setResumeActionId(9);

        self::assertSame(9, $model->getResumeActionId());
    }

    public function testContextRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setContext(['trigger_event' => 'order_placed', 'customer_id' => 7]);

        self::assertSame(['trigger_event' => 'order_placed', 'customer_id' => 7], $model->getContext());
    }

    public function testGetContextReturnsEmptyArrayWhenNeverSet(): void
    {
        $model = $this->makeModel();

        self::assertSame([], $model->getContext());
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
        $model->setLastError('DB is down');

        self::assertSame('DB is down', $model->getLastError());
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
