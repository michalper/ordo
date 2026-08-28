<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignAction;

class CampaignActionTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignAction
    {
        return new CampaignAction($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
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
        $model->setData('params', json_encode(['tag' => 'vip']));

        self::assertSame(['tag' => 'vip'], $model->getParams());
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
        $model->setType('add_tag');

        self::assertSame('add_tag', $model->getType());
    }

    public function testParamsJsonRoundTripSharesStorageWithGetParams(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('{"tag":"vip"}');

        self::assertSame('{"tag":"vip"}', $model->getParamsJson());
        self::assertSame(['tag' => 'vip'], $model->getParams());
    }

    public function testSortOrderRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSortOrder(1);

        self::assertSame(1, $model->getSortOrder());
    }

    public function testDelayMinutesDefaultsToZero(): void
    {
        self::assertSame(0, $this->makeModel()->getDelayMinutes());
    }

    public function testDelayMinutesRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setDelayMinutes(1440);

        self::assertSame(1440, $model->getDelayMinutes());
    }
}
