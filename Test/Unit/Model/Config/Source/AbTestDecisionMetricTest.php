<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\AbTestDecisionMetric;
use PHPUnit\Framework\TestCase;

class AbTestDecisionMetricTest extends TestCase
{
    public function testToOptionArrayListsAllThreeMetrics(): void
    {
        $options = (new AbTestDecisionMetric())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(AbTestDecisionMetric::CTR, $values);
        self::assertContains(AbTestDecisionMetric::CONVERSION_RATE, $values);
        self::assertContains(AbTestDecisionMetric::REVENUE, $values);
        self::assertCount(3, $options);
    }
}
