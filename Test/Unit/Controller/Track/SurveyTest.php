<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\Survey;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection as SurveyPromptCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\Track\DeliveryClaimer;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SurveyTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private SurveyPromptCollectionFactory $surveyPromptCollectionFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private CustomerSession $customerSession;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
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

    private function makeController(): Survey
    {
        return new Survey(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->surveyPromptCollectionFactory,
            // Real collaborators over the same stubs the controller used to hold directly, so
            // every existing expectation (connection->update, session, request params) still
            // exercises the full claim/identity path.
            new DeliveryClaimer($this->resourceConnection),
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class)),
            $this->config
        );
    }

    private function makeCollection(array $prompts): SurveyPromptCollection
    {
        $collection = $this->createStub(SurveyPromptCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($prompts));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullSurveyWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNpsSurveyEnabled')->willReturn(false);

        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['survey' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullSurveyWhenNoIdentifierGiven(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNpsSurveyEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', '']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['survey' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullSurveyWhenNoneQueued(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNpsSurveyEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeCollection([]));

        $this->jsonResult->expects(self::once())->method('setData')->with(['survey' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndReturnsSurveyForAnonymousVisitor(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNpsSurveyEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(9);
        $prompt->method('getQuestion')->willReturn('How likely are you to recommend us?');

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeCollection([$prompt]));
        $this->connection->expects(self::once())->method('update')
            ->with('ordo_survey_prompt', self::isArray(), self::callback(
                fn (array $where) => $where['entity_id = ?'] === 9
            ))
            ->willReturn(1);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'survey' => [
                'id' => 9,
                'question' => 'How likely are you to recommend us?',
            ],
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFallsThroughToNextCandidateWhenClaimLosesRace(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNpsSurveyEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $lost = $this->createStub(SurveyPrompt::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(SurveyPrompt::class);
        $won->method('getId')->willReturn(10);
        $won->method('getQuestion')->willReturn('Second in line');

        $this->surveyPromptCollectionFactory->method('create')->willReturn($this->makeCollection([$lost, $won]));
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
