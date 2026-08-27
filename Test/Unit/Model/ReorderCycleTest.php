<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ReorderCycle;

class ReorderCycleTest extends AbstractModelTestCase
{
    private function makeModel(): ReorderCycle
    {
        return new ReorderCycle($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testConstructsWithoutError(): void
    {
        self::assertInstanceOf(ReorderCycle::class, $this->makeModel());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testGettersReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData([
            'customer_id' => '42',
            'sku' => 'SKU-1',
            'avg_interval_days' => '30',
            'last_order_date' => '2026-01-01',
            'next_expected_date' => '2026-01-31',
            'orders_considered' => '5',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        self::assertSame(42, $model->getCustomerId());
        self::assertSame('SKU-1', $model->getSku());
        self::assertSame(30, $model->getAvgIntervalDays());
        self::assertSame('2026-01-01', $model->getLastOrderDate());
        self::assertSame('2026-01-31', $model->getNextExpectedDate());
        self::assertSame(5, $model->getOrdersConsidered());
        self::assertSame('2026-01-02 00:00:00', $model->getUpdatedAt());
    }
}
