<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\LeadRoutingRule;

class LeadRoutingRuleTest extends AbstractModelTestCase
{
    private function makeModel(): LeadRoutingRule
    {
        return new LeadRoutingRule($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testNameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setName('EU B2B leads');
        self::assertSame('EU B2B leads', $model->getName());
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
        $model->setValue('1');
        self::assertSame('1', $model->getValue());
    }

    public function testRepsRoundTrip(): void
    {
        $model = $this->makeModel();
        $reps = json_encode([['email' => 'rep@example.com', 'name' => 'Rep', 'phone' => '111']]);
        $model->setReps($reps);
        self::assertSame($reps, $model->getReps());
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
