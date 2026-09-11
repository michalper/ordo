<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\AdminActionLog;

class AdminActionLogTest extends AbstractModelTestCase
{
    private function makeModel(): AdminActionLog
    {
        return new AdminActionLog($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setEntityType('campaign');

        self::assertSame('campaign', $model->getData('entity_type'));
    }

    public function testTargetEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTargetEntityId(5);

        self::assertSame(5, $model->getData('target_entity_id'));
    }

    public function testTargetEntityIdAcceptsNull(): void
    {
        $model = $this->makeModel();
        $model->setTargetEntityId(null);

        self::assertNull($model->getData('target_entity_id'));
    }

    public function testActionRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAction(AdminActionLog::ACTION_UPDATE);

        self::assertSame('update', $model->getData('action'));
    }

    public function testAdminUserIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAdminUserId(1);

        self::assertSame(1, $model->getData('admin_user_id'));
    }

    public function testAdminUsernameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAdminUsername('jdoe');

        self::assertSame('jdoe', $model->getData('admin_username'));
    }

    public function testChangesJsonRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setChangesJson('{"name":["Old","New"]}');

        self::assertSame('{"name":["Old","New"]}', $model->getData('changes_json'));
    }
}
