<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\TestCase;

class ConsentTopicTest extends TestCase
{
    public function testPromotionalDefaultsToNotConsented(): void
    {
        self::assertFalse(ConsentTopic::Promotional->defaultsToConsented());
    }

    public function testAccountUpdatesDefaultsToConsented(): void
    {
        self::assertTrue(ConsentTopic::AccountUpdates->defaultsToConsented());
    }
}
