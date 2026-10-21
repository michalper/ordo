<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Magento\Framework\Mail\TransportInterface;

class SpyTransport implements TransportInterface
{
    public bool $sent = false;

    public function sendMessage()
    {
        $this->sent = true;
    }

    public function getMessage()
    {
        return null;
    }
}
