<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ScoreRule;

class ScoreRuleTest extends AbstractModelTestCase
{
    private function makeModel(): ScoreRule
    {
        return new ScoreRule($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testAttributeCodeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAttributeCode('group_id');
        self::assertSame('group_id', $model->getAttributeCode());
    }

    public function testOperatorRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setOperator('equals');
        self::assertSame('equals', $model->getOperator());
    }

    public function testValueRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setValue('3');
        self::assertSame('3', $model->getValue());
    }

    public function testPointsRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setPoints(20);
        self::assertSame(20, $model->getPoints());
    }

    public function testEnabledRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertFalse($model->isEnabled());

        $model->setEnabled(true);
        self::assertTrue($model->isEnabled());
    }

    public function testSortOrderRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSortOrder(5);
        self::assertSame(5, $model->getSortOrder());
    }
}
