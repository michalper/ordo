<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Anomaly\AnomalyAlert;
use Ordo\Automation\Model\Config\Source\AnomalyAlertType;
use PHPUnit\Framework\TestCase;

class AnomalyAlertTypeTest extends TestCase
{
    public function testToOptionArrayListsBothAlertTypes(): void
    {
        $options = (new AnomalyAlertType())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP, $values);
        self::assertContains(AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER, $values);
        self::assertCount(2, $options);
    }
}
