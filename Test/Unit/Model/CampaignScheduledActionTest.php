<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignScheduledAction;

class CampaignScheduledActionTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignScheduledAction
    {
        return new CampaignScheduledAction($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCampaignIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCampaignId(7);

        self::assertSame(7, $model->getCampaignId());
    }

    public function testResumeActionIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setResumeActionId(12);

        self::assertSame(12, $model->getResumeActionId());
    }

    public function testGetContextReturnsEmptyArrayWhenUnset(): void
    {
        self::assertSame([], $this->makeModel()->getContext());
    }

    public function testGetContextReturnsEmptyArrayWhenInvalidJson(): void
    {
        $model = $this->makeModel();
        $model->setData('context', 'not-json');

        self::assertSame([], $model->getContext());
    }

    public function testContextRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setContext(['customer_id' => 5, 'order_id' => 9]);

        self::assertSame(['customer_id' => 5, 'order_id' => 9], $model->getContext());
    }

    public function testRunAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setRunAt('2026-01-01 12:00:00');

        self::assertSame('2026-01-01 12:00:00', $model->getRunAt());
    }

    public function testExecutedAtDefaultsToNull(): void
    {
        self::assertNull($this->makeModel()->getExecutedAt());
    }

    public function testExecutedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setExecutedAt('2026-01-01 12:05:00');

        self::assertSame('2026-01-01 12:05:00', $model->getExecutedAt());
    }
}
