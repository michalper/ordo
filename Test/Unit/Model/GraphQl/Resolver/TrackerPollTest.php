<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\TrackerPoll;
use Ordo\Automation\Model\ResourceModel\Notification\Collection as NotificationCollection;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection as PendingPopupCollection;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection as SurveyPromptCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\Track\DeliveryClaimer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class TrackerPollTest extends TestCase
{
    private PendingPopupCollectionFactory $pendingPopupCollectionFactory;
    private NotificationCollectionFactory $notificationCollectionFactory;
    private SurveyPromptCollectionFactory $surveyPromptCollectionFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private Config $config;

    protected function setUp(): void
    {
        $this->pendingPopupCollectionFactory = $this->createMock(PendingPopupCollectionFactory::class);
        $this->notificationCollectionFactory = $this->createMock(NotificationCollectionFactory::class);
        $this->surveyPromptCollectionFactory = $this->createMock(SurveyPromptCollectionFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);
        $this->config = $this->createStub(Config::class);
    }

    private function makeResolver(): TrackerPoll
    {
        return new TrackerPoll(
            $this->pendingPopupCollectionFactory,
            $this->notificationCollectionFactory,
            $this->surveyPromptCollectionFactory,
            new DeliveryClaimer($this->resourceConnection),
            new CustomerIdResolver(),
            $this->config
        );
    }

    private function makeNotificationCollection(array $notifications): NotificationCollection
    {
        $collection = $this->createStub(NotificationCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($notifications));

        return $collection;
    }

    private function disableEverythingExcept(string ...$enabled): void
    {
        $this->config->method('isPopupEnabled')->willReturn(in_array('popup', $enabled, true));
        $this->config->method('isNotificationEnabled')->willReturn(in_array('notification', $enabled, true));
        $this->config->method('isNpsSurveyEnabled')->willReturn(in_array('survey', $enabled, true));
    }

    private function resolve(TrackerPoll $resolver, ?array $args, int $userId = 0): mixed
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOmitsEveryKeyWhenAllThreeConcernsAreDisabled(): void
    {
        $resolver = $this->makeResolver();
        $this->disableEverythingExcept();

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->resolve($resolver, []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsNullAndEmptyWhenNoIdentifierGiven(): void
    {
        $resolver = $this->makeResolver();
        $this->disableEverythingExcept('popup', 'notification', 'survey');

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertSame(
            ['popup' => null, 'notifications' => [], 'survey' => null],
            $this->resolve($resolver, ['visitorId' => ''])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyQueriesEnabledConcerns(): void
    {
        $resolver = $this->makeResolver();
        $this->disableEverythingExcept('notification');

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([]));

        self::assertSame(
            ['notifications' => []],
            $this->resolve($resolver, ['visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLoggedInCustomerWithNoVisitorIdStillCountsAsAnIdentity(): void
    {
        $resolver = $this->makeResolver();
        $this->disableEverythingExcept('notification');

        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([]));

        self::assertSame(
            ['notifications' => []],
            $this->resolve($resolver, ['visitorId' => ''], userId: 42)
        );
    }
}
