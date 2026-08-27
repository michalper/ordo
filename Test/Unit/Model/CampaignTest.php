<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Campaign;

class CampaignTest extends AbstractModelTestCase
{
    private function makeModel(): Campaign
    {
        return new Campaign($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setEntityId('5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testNameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setName('Welcome campaign');
        self::assertSame('Welcome campaign', $model->getName());
    }

    public function testEnabledRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertFalse($model->isEnabled());

        $model->setEnabled(true);
        self::assertTrue($model->isEnabled());
    }

    public function testTimestampsReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('created_at', '2026-01-01 00:00:00');
        $model->setData('updated_at', '2026-01-02 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getCreatedAt());
        self::assertSame('2026-01-02 00:00:00', $model->getUpdatedAt());
    }
}
