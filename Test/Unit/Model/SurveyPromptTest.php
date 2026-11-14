<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\SurveyPrompt;

class SurveyPromptTest extends AbstractModelTestCase
{
    private function makeModel(): SurveyPrompt
    {
        return new SurveyPrompt($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCustomerId());

        $model->setCustomerId(42);
        self::assertSame(42, $model->getCustomerId());

        $model->setCustomerId(null);
        self::assertNull($model->getCustomerId());
    }

    public function testVisitorIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getVisitorId());

        $model->setVisitorId('v1');
        self::assertSame('v1', $model->getVisitorId());
    }

    public function testQuestionRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setQuestion('How likely are you to recommend us?');
        self::assertSame('How likely are you to recommend us?', $model->getQuestion());
    }

    public function testDeliveredAtRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getDeliveredAt());

        $model->setDeliveredAt('2026-01-01 00:00:00');
        self::assertSame('2026-01-01 00:00:00', $model->getDeliveredAt());
    }

    public function testScoreRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getScore());

        $model->setScore(9);
        self::assertSame(9, $model->getScore());

        $model->setScore(null);
        self::assertNull($model->getScore());
    }

    public function testRespondedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getRespondedAt());

        $model->setRespondedAt('2026-01-01 00:05:00');
        self::assertSame('2026-01-01 00:05:00', $model->getRespondedAt());
    }

    public function testExpiresAtCanBeSet(): void
    {
        $model = $this->makeModel();
        $model->setExpiresAt('2026-02-01 00:00:00');
        self::assertSame('2026-02-01 00:00:00', $model->getData(SurveyPrompt::EXPIRES_AT));
    }

    public function testQuestionTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getQuestionType());

        $model->setQuestionType('choice');
        self::assertSame('choice', $model->getQuestionType());

        $model->setQuestionType(null);
        self::assertNull($model->getQuestionType());
    }

    public function testChoicesRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getChoices());

        $model->setChoices('Red,Blue,Green');
        self::assertSame('Red,Blue,Green', $model->getChoices());

        $model->setChoices(null);
        self::assertNull($model->getChoices());
    }

    public function testResponseTextRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getResponseText());

        $model->setResponseText('Blue');
        self::assertSame('Blue', $model->getResponseText());

        $model->setResponseText(null);
        self::assertNull($model->getResponseText());
    }
}
