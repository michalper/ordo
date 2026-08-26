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
}
