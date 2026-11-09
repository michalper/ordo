<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ScoreRuleWebsite;

class ScoreRuleWebsiteTest extends AbstractModelTestCase
{
    private function makeModel(): ScoreRuleWebsite
    {
        return new ScoreRuleWebsite($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdGetterCastsRawDataToIntOrNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '7');

        self::assertSame(7, $model->getEntityId());
    }

    public function testScoreRuleIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setScoreRuleId(42);

        self::assertSame($model, $result);
        self::assertSame(42, $model->getScoreRuleId());
    }

    public function testWebsiteIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setWebsiteId(1);

        self::assertSame($model, $result);
        self::assertSame(1, $model->getWebsiteId());
    }
}
