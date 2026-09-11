<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\OrderApprovalStatus;
use Ordo\Automation\Model\OrderApproval;
use PHPUnit\Framework\TestCase;

class OrderApprovalStatusTest extends TestCase
{
    public function testToOptionArrayListsAllThreeStatuses(): void
    {
        $options = (new OrderApprovalStatus())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(OrderApproval::STATUS_PENDING, $values);
        self::assertContains(OrderApproval::STATUS_APPROVED, $values);
        self::assertContains(OrderApproval::STATUS_REJECTED, $values);
        self::assertCount(3, $options);
    }
}
