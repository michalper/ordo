<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Track;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\Notification\Collection as NotificationCollection;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection as PendingPopupCollection;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection as SurveyPromptCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\Track\DeliveryClaimer;
use Ordo\Automation\Model\Track\TrackerPollReader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The shared popup/notification/survey resolution both Controller\Track\Poll and
 * Model\GraphQl\Resolver\TrackerPoll delegate to - see this class's own docblock. These tests
 * are what used to live in PollTest/TrackerPollTest before the extraction; those two now only
 * cover their own identity-resolution/transport responsibility.
 */
class TrackerPollReaderTest extends TestCase
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

    private function makeReader(): TrackerPollReader
    {
        return new TrackerPollReader(
            $this->pendingPopupCollectionFactory,
            $this->notificationCollectionFactory,
            $this->surveyPromptCollectionFactory,
            new DeliveryClaimer($this->resourceConnection),
            $this->config
        );
    }

    private function makePopupCollection(array $popups): PendingPopupCollection
    {
        $collection = $this->createStub(PendingPopupCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($popups));

        return $collection;
    }

    private function makeNotificationCollection(array $notifications): NotificationCollection
    {
        $collection = $this->createStub(NotificationCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($notifications));

        return $collection;
    }

    private function makeSurveyCollection(array $prompts): SurveyPromptCollection
    {
        $collection = $this->createStub(SurveyPromptCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($prompts));

        return $collection;
    }

    private function disableEverythingExcept(string ...$enabled): void
    {
        $this->config->method('isPopupEnabled')->willReturn(in_array('popup', $enabled, true));
        $this->config->method('isNotificationEnabled')->willReturn(in_array('notification', $enabled, true));
        $this->config->method('isNpsSurveyEnabled')->willReturn(in_array('survey', $enabled, true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOmitsEveryKeyWhenAllThreeConcernsAreDisabled(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept();

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $reader->resolve(null, ''));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsNullAndEmptyWhenNoIdentifierGiven(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('popup', 'notification', 'survey');

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertSame(
            ['popup' => null, 'notifications' => [], 'survey' => null],
            $reader->resolve(null, '')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyQueriesEnabledConcerns(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('notification');

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([]));

        self::assertSame(['notifications' => []], $reader->resolve(null, 'v1'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testALoggedInCustomerWithNoVisitorIdStillCountsAsAnIdentity(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('notification');

        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([]));

        self::assertSame(['notifications' => []], $reader->resolve(42, ''));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCombinesAllThreeConcernsInOneResponse(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('popup', 'notification', 'survey');

        $popup = $this->createStub(PendingPopup::class);
        $popup->method('getId')->willReturn(9);
        $popup->method('getHeadline')->willReturn('Hello!');
        $popup->method('getBody')->willReturn('Come back soon');
        $popup->method('getCtaLabel')->willReturn('Shop now');
        $popup->method('getCtaUrl')->willReturn('https://example.test/sale');
        $popup->method('getRewardLabel')->willReturn(null);
        $popup->method('getCouponCode')->willReturn(null);

        $notification = $this->createStub(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->method('getHeadline')->willReturn('Back in stock');
        $notification->method('getBody')->willReturn(null);
        $notification->method('getCtaLabel')->willReturn(null);
        $notification->method('getCtaUrl')->willReturn(null);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(12);
        $prompt->method('getQuestion')->willReturn('How likely are you to recommend us?');

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makePopupCollection([$popup]));
        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([$notification]));
        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeSurveyCollection([$prompt]));
        $this->connection->method('update')->willReturn(1);

        self::assertSame(
            [
                'popup' => [
                    'headline' => 'Hello!',
                    'body' => 'Come back soon',
                    'cta_label' => 'Shop now',
                    'cta_url' => 'https://example.test/sale',
                    'reward_label' => null,
                    'coupon_code' => null,
                ],
                'notifications' => [
                    [
                        'id' => 5,
                        'headline' => 'Back in stock',
                        'body' => null,
                        'cta_label' => null,
                        'cta_url' => null,
                    ],
                ],
                'survey' => [
                    'id' => 12,
                    'question' => 'How likely are you to recommend us?',
                ],
            ],
            $reader->resolve(null, 'v1')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAPopupWithAWonRewardTierForwardsRewardLabelAndCouponCode(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('popup');

        $popup = $this->createStub(PendingPopup::class);
        $popup->method('getId')->willReturn(9);
        $popup->method('getHeadline')->willReturn('Spin to win!');
        $popup->method('getBody')->willReturn(null);
        $popup->method('getCtaLabel')->willReturn(null);
        $popup->method('getCtaUrl')->willReturn(null);
        $popup->method('getRewardLabel')->willReturn('10% off');
        $popup->method('getCouponCode')->willReturn('ORDO-WHEEL-ABC123');

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makePopupCollection([$popup]));
        $this->connection->method('update')->willReturn(1);

        self::assertSame(
            [
                'popup' => [
                    'headline' => 'Spin to win!',
                    'body' => null,
                    'cta_label' => null,
                    'cta_url' => null,
                    'reward_label' => '10% off',
                    'coupon_code' => 'ORDO-WHEEL-ABC123',
                ],
            ],
            $reader->resolve(null, 'v1')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFallsThroughToNextPopupCandidateWhenClaimLosesRace(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('popup');

        $lost = $this->createStub(PendingPopup::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(PendingPopup::class);
        $won->method('getId')->willReturn(10);
        $won->method('getHeadline')->willReturn('Second in line');
        $won->method('getBody')->willReturn(null);
        $won->method('getCtaLabel')->willReturn(null);
        $won->method('getCtaUrl')->willReturn(null);
        $won->method('getRewardLabel')->willReturn(null);
        $won->method('getCouponCode')->willReturn(null);

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makePopupCollection([$lost, $won]));
        $this->connection->method('update')->willReturnOnConsecutiveCalls(0, 1);

        self::assertSame(
            [
                'popup' => [
                    'headline' => 'Second in line',
                    'body' => null,
                    'cta_label' => null,
                    'cta_url' => null,
                    'reward_label' => null,
                    'coupon_code' => null,
                ],
            ],
            $reader->resolve(null, 'v1')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFallsThroughToNextSurveyCandidateWhenClaimLosesRace(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('survey');

        $lost = $this->createStub(SurveyPrompt::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(SurveyPrompt::class);
        $won->method('getId')->willReturn(10);
        $won->method('getQuestion')->willReturn('Second in line');

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeSurveyCollection([$lost, $won]));
        $this->connection->method('update')->willReturnOnConsecutiveCalls(0, 1);

        self::assertSame(
            ['survey' => ['id' => 10, 'question' => 'Second in line']],
            $reader->resolve(null, 'v1')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPopupIsNullWhenNoCandidateIsAvailable(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('popup');

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makePopupCollection([]));

        self::assertSame(['popup' => null], $reader->resolve(null, 'v1'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSurveyIsNullWhenNoCandidateIsAvailable(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('survey');

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeSurveyCollection([]));

        self::assertSame(['survey' => null], $reader->resolve(null, 'v1'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsEveryUnreadNotificationRegardlessOfClaim(): void
    {
        $reader = $this->makeReader();
        $this->disableEverythingExcept('notification');

        $one = $this->createStub(Notification::class);
        $one->method('getId')->willReturn(5);
        $one->method('getHeadline')->willReturn('First');
        $one->method('getBody')->willReturn(null);
        $one->method('getCtaLabel')->willReturn(null);
        $one->method('getCtaUrl')->willReturn(null);

        $two = $this->createStub(Notification::class);
        $two->method('getId')->willReturn(6);
        $two->method('getHeadline')->willReturn('Second');
        $two->method('getBody')->willReturn(null);
        $two->method('getCtaLabel')->willReturn(null);
        $two->method('getCtaUrl')->willReturn(null);

        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([$one, $two]));
        $this->connection->expects(self::never())->method('update');

        self::assertSame(
            [
                'notifications' => [
                    ['id' => 5, 'headline' => 'First', 'body' => null, 'cta_label' => null, 'cta_url' => null],
                    ['id' => 6, 'headline' => 'Second', 'body' => null, 'cta_label' => null, 'cta_url' => null],
                ],
            ],
            $reader->resolve(null, 'v1')
        );
    }
}
