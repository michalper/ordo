<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\OrderApproval;

class OrderApprovalTest extends AbstractModelTestCase
{
    private function makeModel(): OrderApproval
    {
        return new OrderApproval($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testIsPendingWhenStatusPending(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_PENDING);

        self::assertTrue($model->isPending());
    }

    public function testIsNotPendingWhenStatusApproved(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_APPROVED);

        self::assertFalse($model->isPending());
    }

    public function testIsNotPendingWhenStatusRejected(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_REJECTED);

        self::assertFalse($model->isPending());
    }
}
