<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\SegmentWebsite;

class SegmentWebsiteTest extends AbstractModelTestCase
{
    private function makeModel(): SegmentWebsite
    {
        return new SegmentWebsite($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdGetterCastsRawDataToIntOrNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '7');

        self::assertSame(7, $model->getEntityId());
    }

    public function testSegmentIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setSegmentId(42);

        self::assertSame($model, $result);
        self::assertSame(42, $model->getSegmentId());
    }

    public function testWebsiteIdGetterAndSetterRoundTrip(): void
    {
        $model = $this->makeModel();

        $result = $model->setWebsiteId(1);

        self::assertSame($model, $result);
        self::assertSame(1, $model->getWebsiteId());
    }
}
