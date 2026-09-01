<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use PHPUnit\Framework\TestCase;

class TriggerEventTest extends TestCase
{
    public function testToOptionArrayContainsAllTriggers(): void
    {
        $values = array_column((new TriggerEvent())->toOptionArray(), 'value');

        self::assertSame(
            [
                CampaignTriggerInterface::TRIGGER_ORDER_PLACED,
                CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED,
                CampaignTriggerInterface::TRIGGER_TAG_ADDED,
                CampaignTriggerInterface::TRIGGER_CART_ABANDONED,
                CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
            ],
            $values
        );
    }
}
