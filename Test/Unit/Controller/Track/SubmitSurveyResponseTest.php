<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\SubmitSurveyResponse;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\SurveyPromptFactory;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SubmitSurveyResponseTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private SurveyPromptFactory $surveyPromptFactory;
    private SurveyPromptResource $surveyPromptResource;
    private CustomerSession $customerSession;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->surveyPromptFactory = $this->createMock(SurveyPromptFactory::class);
        $this->surveyPromptResource = $this->createMock(SurveyPromptResource::class);
        $this->customerSession = $this->createStub(CustomerSession::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): SubmitSurveyResponse
    {
        return new SubmitSurveyResponse(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->surveyPromptFactory,
            $this->surveyPromptResource,
            // A real resolver over the stubbed session, so identity still flows from the same
            // request params and session state the controller used to read inline.
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class))
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenSurveyIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['survey_id', null], ['score', 5]]);

        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenScoreIsNotNumeric(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 'high'], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        // A loaded, owned, legacy (question_type null) prompt - which params are checked (score
        // vs response_text) depends on the prompt actually loaded, not which params the request
        // happened to send, so the prompt must load successfully to reach that branch at all.
        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenScoreOutOfRange(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 11], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenPromptDoesNotExist(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 8], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(null);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('load')->with($prompt, 5);
        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenAlreadyResponded(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 8], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn('2026-01-01 00:00:00');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenPromptBelongsToSomeoneElse(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 8], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        // The ownership rule itself lives in VisitorIdentityFieldsTrait::belongsTo() (covered in
        // Test\Unit\Model\NotificationTest); here only that the controller honors it — and feeds
        // it the resolver-derived identity, never a client-supplied customer_id.
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(false);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecordsScoreAndReturnsTrueWhenOwnedByVisitor(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 8], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $prompt->expects(self::once())->method('setScore')->with(8);
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecordsScoreAndReturnsTrueWhenOwnedByLoggedInCustomer(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['score', 0], ['visitor_id', ''],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(42, '')->willReturn(true);
        $prompt->expects(self::once())->method('setScore')->with(0);
        $prompt->expects(self::once())->method('setRespondedAt');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenResponseTextIsEmptyForATextQuestion(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['response_text', ''], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('text');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenChoiceAnswerIsNotOneOfTheOfferedChoices(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['response_text', 'Purple'], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('choice');
        $prompt->method('getChoices')->willReturn('Red,Blue,Green');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecordsResponseTextAndReturnsTrueForAChoiceQuestion(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['response_text', ' Blue '], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('choice');
        $prompt->method('getChoices')->willReturn('Red,Blue,Green');
        $prompt->expects(self::once())->method('setResponseText')->with('Blue');
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecordsResponseTextAndReturnsTrueForATextQuestion(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['survey_id', 5], ['response_text', 'Running shoes'], ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('text');
        $prompt->expects(self::once())->method('setResponseText')->with('Running shoes');
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

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
