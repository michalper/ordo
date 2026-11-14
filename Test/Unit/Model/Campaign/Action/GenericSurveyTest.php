<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\ContextTargetResolver;
use Ordo\Automation\Model\Campaign\Action\GenericSurvey;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\SurveyPromptFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class GenericSurveyTest extends TestCase
{
    private SurveyPromptFactory&\PHPUnit\Framework\MockObject\MockObject $surveyPromptFactory;
    private SurveyPromptResource&\PHPUnit\Framework\MockObject\MockObject $surveyPromptResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private GenericSurvey $action;

    protected function setUp(): void
    {
        $this->surveyPromptFactory = $this->createMock(SurveyPromptFactory::class);
        $this->surveyPromptResource = $this->createMock(SurveyPromptResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = new GenericSurvey(
            $this->surveyPromptFactory,
            $this->surveyPromptResource,
            new ContextTargetResolver(),
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesTextPromptForCustomer(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->expects(self::once())->method('setCustomerId')->with(42);
        $prompt->expects(self::once())->method('setVisitorId')->with(null);
        $prompt->expects(self::once())->method('setQuestion')->with('What is your favorite category?');
        $prompt->expects(self::once())->method('setQuestionType')->with('text');
        $prompt->expects(self::once())->method('setChoices')->with(null);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);
        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'question' => 'What is your favorite category?',
            'question_type' => 'text',
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesChoicePromptWithChoicesForAnonymousVisitor(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->expects(self::once())->method('setCustomerId')->with(null);
        $prompt->expects(self::once())->method('setVisitorId')->with('v1');
        $prompt->expects(self::once())->method('setQuestionType')->with('choice');
        $prompt->expects(self::once())->method('setChoices')->with('Red,Blue,Green');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);
        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        $context = ['visitor_id' => 'v1'];
        $this->action->execute($context, [
            'question' => 'Favorite color?',
            'question_type' => 'choice',
            'choices' => 'Red,Blue,Green',
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['question' => 'Favorite color?', 'question_type' => 'text']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenQuestionMissing(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['question_type' => 'text']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenQuestionTypeIsUnrecognized(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['question' => 'Favorite color?', 'question_type' => 'score']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenChoiceQuestionHasNoChoices(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['question' => 'Favorite color?', 'question_type' => 'choice']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsQuestionQuestionTypeAndChoices(): void
    {
        self::assertSame(
            ['question', 'question_type', 'choices'],
            array_column($this->action->getParamFields(), 'name')
        );
    }
}
