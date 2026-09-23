<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\AbandonedCartFallbackChannel;
use PHPUnit\Framework\TestCase;

class AbandonedCartFallbackChannelTest extends TestCase
{
    public function testToOptionArrayListsBothChannels(): void
    {
        $options = (new AbandonedCartFallbackChannel())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(AbandonedCartFallbackChannel::SMS, $values);
        self::assertContains(AbandonedCartFallbackChannel::WHATSAPP, $values);
        self::assertCount(2, $options);
    }
}
