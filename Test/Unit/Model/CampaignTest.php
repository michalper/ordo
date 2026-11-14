<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Campaign;

class CampaignTest extends AbstractModelTestCase
{
    private function makeModel(): Campaign
    {
        return new Campaign($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setEntityId('5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testNameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setName('Welcome campaign');
        self::assertSame('Welcome campaign', $model->getName());
    }

    public function testEnabledRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertFalse($model->isEnabled());

        $model->setEnabled(true);
        self::assertTrue($model->isEnabled());
    }

    public function testTimestampsReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('created_at', '2026-01-01 00:00:00');
        $model->setData('updated_at', '2026-01-02 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getCreatedAt());
        self::assertSame('2026-01-02 00:00:00', $model->getUpdatedAt());
    }

    public function testConditionLogicDefaultsToAllWhenNeverSet(): void
    {
        self::assertSame('all', $this->makeModel()->getConditionLogic());
    }

    public function testConditionLogicRoundTripsAny(): void
    {
        $model = $this->makeModel();
        $model->setConditionLogic('any');

        self::assertSame('any', $model->getConditionLogic());
    }

    public function testConditionLogicNormalizesAnyOtherValueToAll(): void
    {
        $model = $this->makeModel();
        $model->setConditionLogic('bogus');

        self::assertSame('all', $model->getConditionLogic());
    }

    public function testExitConditionsDefaultsToNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getExitConditions());
    }

    public function testExitConditionsRoundTrips(): void
    {
        $model = $this->makeModel();
        $json = json_encode([['type' => 'has_converted', 'params' => []]]);
        $model->setExitConditions($json);

        self::assertSame($json, $model->getExitConditions());
    }

    public function testExitConditionsTreatsAnEmptyStringAsNull(): void
    {
        $model = $this->makeModel();
        $model->setExitConditions('');

        self::assertNull($model->getExitConditions());
    }

    public function testHoldoutPercentDefaultsToZeroWhenNeverSet(): void
    {
        self::assertSame(0, $this->makeModel()->getHoldoutPercent());
    }

    public function testHoldoutPercentRoundTrips(): void
    {
        $model = $this->makeModel();
        $model->setHoldoutPercent(25);

        self::assertSame(25, $model->getHoldoutPercent());
    }

    public function testSetHoldoutPercentClampsAboveOneHundred(): void
    {
        $model = $this->makeModel();
        $model->setHoldoutPercent(150);

        self::assertSame(100, $model->getHoldoutPercent());
    }

    public function testSetHoldoutPercentClampsBelowZero(): void
    {
        $model = $this->makeModel();
        $model->setHoldoutPercent(-10);

        self::assertSame(0, $model->getHoldoutPercent());
    }

    public function testGetHoldoutPercentClampsAnOutOfRangeValueWrittenDirectly(): void
    {
        $model = $this->makeModel();
        $model->setData('holdout_percent', 200);

        self::assertSame(100, $model->getHoldoutPercent());
    }
}
