<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPromptFactory;
use Psr\Log\LoggerInterface;

/**
 * Params: {"question": "...", "question_type": "text"|"choice", "choices": "Red,Blue,Green"}.
 * Sibling of NpsSurvey on the same ordo_survey_prompt claim/deliver/respond lifecycle (same
 * queue-and-poll delivery via Controller\Track\Poll, same ContextTargetResolver target rules,
 * same no-frequency-cap reasoning) — kept as a separate class and a separate "generic_survey"
 * di.xml entry rather than widening NpsSurvey itself with a question_type param, so an existing
 * nps_survey campaign action's params/behavior are untouched by this feature (see docs/adr
 * 0061). Where this differs from NpsSurvey: it asks a free-text or multiple-choice question
 * instead of a fixed 0-10 score, so Controller\Track\SubmitSurveyResponse records the answer in
 * response_text rather than score.
 *
 * "choice" questions store their offered options as a plain comma-separated string in
 * `choices` - no separate options/choices table for v1 (see the ADR for why), so a choice
 * containing a literal comma isn't supported.
 */
class GenericSurvey implements ActionInterface
{
    private const array QUESTION_TYPES = ['text', 'choice'];

    public function __construct(
        private readonly SurveyPromptFactory $surveyPromptFactory,
        private readonly SurveyPromptResource $surveyPromptResource,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $target = $this->contextTargetResolver->resolveCustomerOrVisitor($context);
        $question = trim((string) ($params['question'] ?? ''));
        $questionType = trim((string) ($params['question_type'] ?? ''));
        $choices = trim((string) ($params['choices'] ?? ''));

        if ($target->isEmpty()) {
            $this->logger->error(
                'Ordo_Automation: generic_survey action has no customer_id or visitor_id in context to target.'
            );
            return;
        }

        if ($question === '') {
            $this->logger->error('Ordo_Automation: generic_survey action is missing a question.');
            return;
        }

        if (!in_array($questionType, self::QUESTION_TYPES, true)) {
            $this->logger->error(
                sprintf('Ordo_Automation: generic_survey action has an unrecognized question_type "%s".', $questionType)
            );
            return;
        }

        if ($questionType === 'choice' && $choices === '') {
            $this->logger->error('Ordo_Automation: generic_survey action is a choice question with no choices.');
            return;
        }

        $prompt = $this->surveyPromptFactory->create();
        $prompt->setCustomerId($target->customerId);
        $prompt->setVisitorId($target->visitorId);
        $prompt->setQuestion($question);
        $prompt->setQuestionType($questionType);
        $prompt->setChoices($questionType === 'choice' ? $choices : null);

        $this->surveyPromptResource->save($prompt);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'question', 'label' => (string) __('Question')],
            [
                'name' => 'question_type',
                'label' => (string) __('Question type'),
                'options' => [
                    'text' => (string) __('Free text'),
                    'choice' => (string) __('Multiple choice'),
                ],
            ],
            [
                'name' => 'choices',
                'label' => (string) __('Choices'),
                'notice' => (string) __('Comma-separated list of options - multiple choice questions only.'),
            ],
        ];
    }
}
