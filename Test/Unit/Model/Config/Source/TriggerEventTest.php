<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use PHPUnit\Framework\TestCase;

class TriggerEventTest extends TestCase
{
    public function testToOptionArrayContainsAllTriggers(): void
    {
        $values = array_column((new TriggerEvent())->toOptionArray(), 'value');

        self::assertSame(
            [
                CampaignInterface::TRIGGER_ORDER_PLACED,
                CampaignInterface::TRIGGER_CUSTOMER_REGISTERED,
                CampaignInterface::TRIGGER_TAG_ADDED,
                CampaignInterface::TRIGGER_CART_ABANDONED,
            ],
            $values
        );
    }
}
