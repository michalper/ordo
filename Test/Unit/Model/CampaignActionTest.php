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
}
