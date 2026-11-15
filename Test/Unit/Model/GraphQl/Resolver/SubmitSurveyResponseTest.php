<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\SubmitSurveyResponse;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\SurveyPromptFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SubmitSurveyResponseTest extends TestCase
{
    private SurveyPromptFactory $surveyPromptFactory;
    private SurveyPromptResource $surveyPromptResource;

    protected function setUp(): void
    {
        $this->surveyPromptFactory = $this->createMock(SurveyPromptFactory::class);
        $this->surveyPromptResource = $this->createMock(SurveyPromptResource::class);
    }

    private function makeResolver(): SubmitSurveyResponse
    {
        return new SubmitSurveyResponse(
            $this->surveyPromptFactory,
            $this->surveyPromptResource,
            new CustomerIdResolver()
        );
    }

    private function resolve(SubmitSurveyResponse $resolver, array $args, int $userId = 0): mixed
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
    public function testReturnsFalseWhenSurveyIdMissing(): void
    {
        $resolver = $this->makeResolver();

        $this->surveyPromptFactory->expects(self::never())->method('create');

        self::assertSame(['ok' => false], $this->resolve($resolver, ['score' => 5]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenScoreIsNotNumeric(): void
    {
        $resolver = $this->makeResolver();

        // A loaded, owned, legacy (question_type null) prompt - which args are checked (score
        // vs responseText) depends on the prompt actually loaded, not which args the request
        // happened to send, so the prompt must load successfully to reach that branch at all.
        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 'high', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenScoreOutOfRange(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 11, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenPromptDoesNotExist(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(null);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('load')->with($prompt, 5);
        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 8, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenAlreadyResponded(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn('2026-01-01 00:00:00');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 8, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenPromptBelongsToSomeoneElse(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(false);
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 8, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordsScoreAndReturnsTrueWhenOwnedByVisitor(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $prompt->expects(self::once())->method('setScore')->with(8);
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 8, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordsScoreAndReturnsTrueWhenOwnedByLoggedInCustomer(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(42, '')->willReturn(true);
        $prompt->expects(self::once())->method('setScore')->with(0);
        $prompt->expects(self::once())->method('setRespondedAt');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['surveyId' => 5, 'score' => 0], userId: 42)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenResponseTextIsEmptyForATextQuestion(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('text');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'responseText' => '', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenChoiceAnswerIsNotOneOfTheOfferedChoices(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->method('belongsTo')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('choice');
        $prompt->method('getChoices')->willReturn('Red,Blue,Green');
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['surveyId' => 5, 'responseText' => 'Purple', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordsResponseTextAndReturnsTrueForAChoiceQuestion(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('choice');
        $prompt->method('getChoices')->willReturn('Red,Blue,Green');
        $prompt->expects(self::once())->method('setResponseText')->with('Blue');
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['surveyId' => 5, 'responseText' => ' Blue ', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecordsResponseTextAndReturnsTrueForATextQuestion(): void
    {
        $resolver = $this->makeResolver();

        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getId')->willReturn(5);
        $prompt->method('getRespondedAt')->willReturn(null);
        $prompt->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $prompt->method('getQuestionType')->willReturn('text');
        $prompt->expects(self::once())->method('setResponseText')->with('Running shoes');
        $prompt->expects(self::once())->method('setRespondedAt')->with(self::callback('is_string'));
        $this->surveyPromptFactory->method('create')->willReturn($prompt);

        $this->surveyPromptResource->expects(self::once())->method('save')->with($prompt);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['surveyId' => 5, 'responseText' => 'Running shoes', 'visitorId' => 'v1'])
        );
    }
}
