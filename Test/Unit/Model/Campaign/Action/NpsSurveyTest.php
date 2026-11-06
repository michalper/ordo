<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\ContextTargetResolver;
use Ordo\Automation\Model\Campaign\Action\NpsSurvey;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\SurveyPromptFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class NpsSurveyTest extends TestCase
{
    private SurveyPromptFactory&\PHPUnit\Framework\MockObject\MockObject $surveyPromptFactory;
    private SurveyPromptResource&\PHPUnit\Framework\MockObject\MockObject $surveyPromptResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private NpsSurvey $action;

    protected function setUp(): void
    {
        $this->surveyPromptFactory = $this->createMock(SurveyPromptFactory::class);
        $this->surveyPromptResource = $this->createMock(SurveyPromptResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = new NpsSurvey(
            $this->surveyPromptFactory,
            $this->surveyPromptResource,
            new ContextTargetResolver(),
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesPromptForCustomer(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->expects(self::once())->method('setCustomerId')->with(42);
        $prompt->expects(self::once())->method('setVisitorId')->with(null);
        $prompt->expects(self::once())->method('setQuestion')->with('How likely are you to recommend us?');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);
        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['question' => 'How likely are you to recommend us?']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesPromptForAnonymousVisitor(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->expects(self::once())->method('setCustomerId')->with(null);
        $prompt->expects(self::once())->method('setVisitorId')->with('v1');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);
        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        $context = ['visitor_id' => 'v1'];
        $this->action->execute($context, ['question' => 'How likely?']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['question' => 'How likely?']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenQuestionMissing(): void
    {
        $this->surveyPromptFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    public function testGetParamFieldsListsQuestion(): void
    {
        self::assertSame(['question'], array_column($this->action->getParamFields(), 'name'));
    }
}
