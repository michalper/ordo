<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ConsentTopicOptions;
use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\TestCase;

class ConsentTopicOptionsTest extends TestCase
{
    public function testToOptionArrayListsBothTopicsPlusTheBlankDefaultOption(): void
    {
        $options = (new ConsentTopicOptions())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains('', $values);
        self::assertContains(ConsentTopic::Promotional->value, $values);
        self::assertContains(ConsentTopic::AccountUpdates->value, $values);
        self::assertCount(3, $options);
    }
}
