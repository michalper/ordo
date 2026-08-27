<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignCondition;

class CampaignConditionTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignCondition
    {
        return new CampaignCondition($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGetParamsReturnsEmptyArrayWhenUnset(): void
    {
        self::assertSame([], $this->makeModel()->getParams());
    }

    public function testGetParamsReturnsEmptyArrayWhenInvalidJson(): void
    {
        $model = $this->makeModel();
        $model->setData('params', 'not-json');

        self::assertSame([], $model->getParams());
    }

    public function testGetParamsDecodesValidJson(): void
    {
        $model = $this->makeModel();
        $model->setData('params', json_encode(['amount' => 100]));

        self::assertSame(['amount' => 100], $model->getParams());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setEntityId(5);
        self::assertSame(5, $model->getEntityId());
    }

    public function testCampaignIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCampaignId(3);

        self::assertSame(3, $model->getCampaignId());
    }

    public function testTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setType('order_total_gte');

        self::assertSame('order_total_gte', $model->getType());
    }

    public function testParamsJsonRoundTripSharesStorageWithGetParams(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('{"amount":"500"}');

        self::assertSame('{"amount":"500"}', $model->getParamsJson());
        self::assertSame(['amount' => '500'], $model->getParams());
    }

    public function testSortOrderRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSortOrder(2);

        self::assertSame(2, $model->getSortOrder());
    }
}
