<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\Poll;
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
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class PollTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private PendingPopupCollectionFactory $pendingPopupCollectionFactory;
    private NotificationCollectionFactory $notificationCollectionFactory;
    private SurveyPromptCollectionFactory $surveyPromptCollectionFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private CustomerSession $customerSession;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->pendingPopupCollectionFactory = $this->createMock(PendingPopupCollectionFactory::class);
        $this->notificationCollectionFactory = $this->createMock(NotificationCollectionFactory::class);
        $this->surveyPromptCollectionFactory = $this->createMock(SurveyPromptCollectionFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Poll
    {
        return new Poll(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->pendingPopupCollectionFactory,
            $this->notificationCollectionFactory,
            $this->surveyPromptCollectionFactory,
            // Real collaborators over the same stubs the old per-concern controllers used to
            // hold directly, so every existing expectation (connection->update, session, request
            // params) still exercises the full claim/identity path.
            new DeliveryClaimer($this->resourceConnection),
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class)),
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
    public function testExecuteOmitsEveryKeyWhenAllThreeConcernsAreDisabled(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept();

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with([]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullAndEmptyWhenNoIdentifierGiven(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('popup', 'notification', 'survey');
        $this->request->method('getParam')->willReturnMap([['visitor_id', '']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with([
            'popup' => null,
            'notifications' => [],
            'survey' => null,
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteOnlyQueriesEnabledConcerns(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('notification');
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->notificationCollectionFactory->method('create')->willReturn($this->makeNotificationCollection([]));

        $this->jsonResult->expects(self::once())->method('setData')->with(['notifications' => []]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCombinesAllThreeConcernsInOneResponse(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('popup', 'notification', 'survey');
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $popup = $this->createStub(PendingPopup::class);
        $popup->method('getId')->willReturn(9);
        $popup->method('getHeadline')->willReturn('Hello!');
        $popup->method('getBody')->willReturn('Come back soon');
        $popup->method('getCtaLabel')->willReturn('Shop now');
        $popup->method('getCtaUrl')->willReturn('https://example.test/sale');

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

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'popup' => [
                'headline' => 'Hello!',
                'body' => 'Come back soon',
                'cta_label' => 'Shop now',
                'cta_url' => 'https://example.test/sale',
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
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFallsThroughToNextPopupCandidateWhenClaimLosesRace(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('popup');
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $lost = $this->createStub(PendingPopup::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(PendingPopup::class);
        $won->method('getId')->willReturn(10);
        $won->method('getHeadline')->willReturn('Second in line');
        $won->method('getBody')->willReturn(null);
        $won->method('getCtaLabel')->willReturn(null);
        $won->method('getCtaUrl')->willReturn(null);

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makePopupCollection([$lost, $won]));
        $this->connection->method('update')->willReturnOnConsecutiveCalls(0, 1);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'popup' => [
                'headline' => 'Second in line',
                'body' => null,
                'cta_label' => null,
                'cta_url' => null,
            ],
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFallsThroughToNextSurveyCandidateWhenClaimLosesRace(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('survey');
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $lost = $this->createStub(SurveyPrompt::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(SurveyPrompt::class);
        $won->method('getId')->willReturn(10);
        $won->method('getQuestion')->willReturn('Second in line');

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeSurveyCollection([$lost, $won]));
        $this->connection->method('update')->willReturnOnConsecutiveCalls(0, 1);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'survey' => [
                'id' => 10,
                'question' => 'Second in line',
            ],
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEveryUnreadNotificationRegardlessOfClaim(): void
    {
        $controller = $this->makeController();
        $this->disableEverythingExcept('notification');
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

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

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'notifications' => [
                ['id' => 5, 'headline' => 'First', 'body' => null, 'cta_label' => null, 'cta_url' => null],
                ['id' => 6, 'headline' => 'Second', 'body' => null, 'cta_label' => null, 'cta_url' => null],
            ],
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
