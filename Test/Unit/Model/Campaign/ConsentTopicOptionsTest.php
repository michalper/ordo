<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\ConsentTopicOptions;
use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\TestCase;

class ConsentTopicOptionsTest extends TestCase
{
    public function testToMapListsBothTopicsPlusTheDefaultOption(): void
    {
        self::assertSame(
            ['', ConsentTopic::Promotional->value, ConsentTopic::AccountUpdates->value],
            array_keys(ConsentTopicOptions::toMap())
        );
    }

    /**
     * Every send_* campaign action's "topic" field (SendEmail, SendPush, and - via
     * AbstractPhoneMessageAction - SendSms/SendRcs/SendWhatsApp) shares this exact field
     * definition, not just the options map.
     */
    public function testFieldReturnsTheSharedFieldDefinition(): void
    {
        $field = ConsentTopicOptions::field();

        self::assertSame('topic', $field['name']);
        self::assertSame(ConsentTopicOptions::toMap(), $field['options']);
        self::assertNotSame('', $field['label']);
        self::assertNotSame('', $field['notice']);
    }
}
