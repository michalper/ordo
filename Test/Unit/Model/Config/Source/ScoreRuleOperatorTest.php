<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ScoreRuleOperator;
use PHPUnit\Framework\TestCase;

class ScoreRuleOperatorTest extends TestCase
{
    public function testToOptionArrayReturnsAllThreeOperators(): void
    {
        $options = (new ScoreRuleOperator())->toOptionArray();

        self::assertSame(
            [ScoreRuleOperator::EQUALS, ScoreRuleOperator::NOT_EQUALS, ScoreRuleOperator::CONTAINS],
            array_column($options, 'value')
        );
        self::assertSame('Equals', (string) $options[0]['label']);
        self::assertSame('Not Equals', (string) $options[1]['label']);
        self::assertSame('Contains', (string) $options[2]['label']);
    }
}
