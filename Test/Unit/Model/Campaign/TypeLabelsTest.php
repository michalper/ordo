<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\TypeLabels;
use PHPUnit\Framework\TestCase;

class TypeLabelsTest extends TestCase
{
    public function testConditionLabelUsesKnownMapping(): void
    {
        self::assertSame('Order Total ≥', (new TypeLabels())->conditionLabel('order_total_gte'));
    }

    public function testActionLabelUsesKnownMapping(): void
    {
        self::assertSame('Send Email', (new TypeLabels())->actionLabel('send_email'));
    }

    public function testConditionLabelHumanizesUnknownType(): void
    {
        self::assertSame('Custom Loyalty Tier', (new TypeLabels())->conditionLabel('custom_loyalty_tier'));
    }

    public function testActionLabelHumanizesUnknownType(): void
    {
        self::assertSame('Push Notification', (new TypeLabels())->actionLabel('push_notification'));
    }
}
