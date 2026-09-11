<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdminActionLog;

use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\AdminActionLogFactory;
use Ordo\Automation\Model\ResourceModel\AdminActionLog as AdminActionLogResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecorderTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testRecordPersistsEntryWithCurrentAdminUser(): void
    {
        $user = new UserTestDouble()->setTestUserId(5)->setTestUserName('jdoe');
        $authSession = new BackendAuthSessionTestDouble()->setTestUser($user);

        $entry = $this->createMock(AdminActionLog::class);
        $entry->expects(self::once())->method('setEntityType')->with('campaign');
        $entry->expects(self::once())->method('setTargetEntityId')->with(7);
        $entry->expects(self::once())->method('setAction')->with(AdminActionLog::ACTION_UPDATE);
        $entry->expects(self::once())->method('setAdminUserId')->with(5);
        $entry->expects(self::once())->method('setAdminUsername')->with('jdoe');
        $entry->expects(self::once())->method('setChangesJson')
            ->with(self::callback(fn ($json) => str_contains((string) $json, '"name"')));

        $factory = $this->createStub(AdminActionLogFactory::class);
        $factory->method('create')->willReturn($entry);
        $resource = $this->createMock(AdminActionLogResource::class);
        $resource->expects(self::once())->method('save')->with($entry);

        $recorder = new Recorder($authSession, $factory, $resource, $this->createStub(LoggerInterface::class));
        $recorder->record('campaign', 7, AdminActionLog::ACTION_UPDATE, ['name' => ['Old', 'New']]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordHandlesNoLoggedInUser(): void
    {
        $authSession = new BackendAuthSessionTestDouble();

        $entry = $this->createMock(AdminActionLog::class);
        $entry->expects(self::once())->method('setAdminUserId')->with(null);
        $entry->expects(self::once())->method('setAdminUsername')->with(null);
        $entry->expects(self::once())->method('setChangesJson')->with(null);

        $factory = $this->createStub(AdminActionLogFactory::class);
        $factory->method('create')->willReturn($entry);
        $resource = $this->createMock(AdminActionLogResource::class);
        $resource->expects(self::once())->method('save');

        $recorder = new Recorder($authSession, $factory, $resource, $this->createStub(LoggerInterface::class));
        $recorder->record('campaign', 9, AdminActionLog::ACTION_CREATE, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordSwallowsAndLogsPersistFailure(): void
    {
        $authSession = new BackendAuthSessionTestDouble();

        $factory = $this->createStub(AdminActionLogFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(AdminActionLogResource::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $recorder = new Recorder($authSession, $factory, $resource, $logger);

        // Must not throw - a DB hiccup persisting the audit row can't crash the actual save.
        $recorder->record('campaign', 9, AdminActionLog::ACTION_CREATE, null);
    }

    public function testHasLoggedInAdminIsTrueWhenAUserIsLoggedIn(): void
    {
        $user = new UserTestDouble()->setTestUserId(5)->setTestUserName('jdoe');
        $authSession = new BackendAuthSessionTestDouble()->setTestUser($user);

        $recorder = new Recorder(
            $authSession,
            $this->createStub(AdminActionLogFactory::class),
            $this->createStub(AdminActionLogResource::class),
            $this->createStub(LoggerInterface::class)
        );

        self::assertTrue($recorder->hasLoggedInAdmin());
    }

    public function testHasLoggedInAdminIsFalseWithNoUser(): void
    {
        $recorder = new Recorder(
            new BackendAuthSessionTestDouble(),
            $this->createStub(AdminActionLogFactory::class),
            $this->createStub(AdminActionLogResource::class),
            $this->createStub(LoggerInterface::class)
        );

        self::assertFalse($recorder->hasLoggedInAdmin());
    }

    public function testDiffFieldsReturnsOnlyChangedFields(): void
    {
        $entity = $this->createStub(AbstractModel::class);
        $entity->method('getOrigData')->willReturnMap([
            ['name', 'Old Name'],
            ['enabled', true],
            ['condition_logic', 'all'],
        ]);
        $entity->method('getData')->willReturnMap([
            ['name', 'New Name'],
            ['enabled', true],
            ['condition_logic', 'all'],
        ]);

        $recorder = new Recorder(
            new BackendAuthSessionTestDouble(),
            $this->createStub(AdminActionLogFactory::class),
            $this->createStub(AdminActionLogResource::class),
            $this->createStub(LoggerInterface::class)
        );

        self::assertSame(
            ['name' => ['Old Name', 'New Name']],
            $recorder->diffFields($entity, ['name', 'enabled', 'condition_logic'])
        );
    }
}
