<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignWebsite;

class CampaignWebsiteTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignWebsite
    {
        return new CampaignWebsite($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdGetterCastsRawDataToIntOrNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getEntityId());

        $model->setEntityId('7');

        self::assertSame(7, $model->getEntityId());
    }

    public function testCampaignIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setCampaignId(42);

        self::assertSame($model, $result);
        self::assertSame(42, $model->getCampaignId());
    }

    public function testWebsiteIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setWebsiteId(1);

        self::assertSame($model, $result);
        self::assertSame(1, $model->getWebsiteId());
    }
}
