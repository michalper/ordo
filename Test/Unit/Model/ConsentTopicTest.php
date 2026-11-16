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

    public function testFromParamsResolvesAnExplicitValidTopic(): void
    {
        self::assertSame(ConsentTopic::AccountUpdates, ConsentTopic::fromParams(['topic' => 'account_updates']));
    }

    public function testFromParamsDefaultsToPromotionalWhenTopicIsMissing(): void
    {
        self::assertSame(ConsentTopic::Promotional, ConsentTopic::fromParams([]));
    }

    public function testFromParamsDefaultsToPromotionalWhenTopicIsInvalid(): void
    {
        self::assertSame(ConsentTopic::Promotional, ConsentTopic::fromParams(['topic' => 'not-a-real-topic']));
    }
}
