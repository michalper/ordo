<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Anomaly;

use Ordo\Automation\Model\Anomaly\AnomalyAlert;
use Ordo\Automation\Test\Unit\Model\AbstractModelTestCase;

class AnomalyAlertTest extends AbstractModelTestCase
{
    private function makeModel(): AnomalyAlert
    {
        return new AnomalyAlert($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersCastRawDataToTheirDeclaredTypes(): void
    {
        $model = $this->makeModel();
        $model->setData([
            'alert_type' => AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP,
            'campaign_id' => '42',
            'message' => 'Campaign #42 revenue dropped 35.0% (100.00 -> 65.00 over 7 days)',
            'current_value' => '65.5',
            'baseline_value' => '100.25',
            'deviation_percent' => '35.0',
            'detected_at' => '2026-11-08 09:00:00',
        ]);

        self::assertSame(AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP, $model->getAlertType());
        self::assertSame(42, $model->getCampaignId());
        self::assertSame(
            'Campaign #42 revenue dropped 35.0% (100.00 -> 65.00 over 7 days)',
            $model->getMessage()
        );
        self::assertSame(65.5, $model->getCurrentValue());
        self::assertSame(100.25, $model->getBaselineValue());
        self::assertSame(35.0, $model->getDeviationPercent());
        self::assertSame('2026-11-08 09:00:00', $model->getDetectedAt());
    }

    public function testGetDetectedAtReturnsNullWhenUnset(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getDetectedAt());
    }
}
