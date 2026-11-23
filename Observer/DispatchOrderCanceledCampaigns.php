<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

class DispatchOrderCanceledCampaigns extends AbstractDispatchOrderCampaigns
{
    protected function triggerEventCode(): string
    {
        return 'order_canceled';
    }
}
