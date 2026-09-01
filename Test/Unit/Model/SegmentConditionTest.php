<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\SegmentCondition;

class SegmentConditionTest extends AbstractModelTestCase
{
    private function makeModel(): SegmentCondition
    {
        return new SegmentCondition($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
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
        $model->setData('params', json_encode(['min' => '500']));

        self::assertSame(['min' => '500'], $model->getParams());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testSegmentIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSegmentId(3);

        self::assertSame(3, $model->getSegmentId());
    }

    public function testTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setType('lifetime_spend');

        self::assertSame('lifetime_spend', $model->getType());
    }

    public function testParamsJsonRoundTripSharesStorageWithGetParams(): void
    {
        $model = $this->makeModel();
        $model->setParamsJson('{"min":"500"}');

        self::assertSame('{"min":"500"}', $model->getParamsJson());
        self::assertSame(['min' => '500'], $model->getParams());
    }

    public function testSortOrderRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSortOrder(1);

        self::assertSame(1, $model->getSortOrder());
    }
}
