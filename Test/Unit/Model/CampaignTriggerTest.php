<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignTrigger;

class CampaignTriggerTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignTrigger
    {
        return new CampaignTrigger($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setEntityId(7);
        self::assertSame(7, $model->getEntityId());
    }

    public function testCampaignIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCampaignId(3);

        self::assertSame(3, $model->getCampaignId());
    }

    public function testTriggerEventRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTriggerEvent('customer_registered');

        self::assertSame('customer_registered', $model->getTriggerEvent());
    }

    public function testParamsJsonRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('{"scheduled_at":"2026-11-28 09:00:00"}');

        self::assertSame('{"scheduled_at":"2026-11-28 09:00:00"}', $model->getParamsJson());
    }

    public function testGetParamsDecodesJson(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('{"scheduled_at":"2026-11-28 09:00:00"}');

        self::assertSame(['scheduled_at' => '2026-11-28 09:00:00'], $model->getParams());
    }

    public function testGetParamsReturnsEmptyArrayWhenParamsJsonIsEmpty(): void
    {
        $model = $this->makeModel();

        self::assertSame([], $model->getParams());
    }

    public function testGetParamsReturnsEmptyArrayWhenParamsJsonIsNotAJsonObjectOrArray(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('"just a string"');

        self::assertSame([], $model->getParams());
    }
}
