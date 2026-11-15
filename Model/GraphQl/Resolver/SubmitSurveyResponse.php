<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPromptFactory;

/**
 * GraphQL counterpart of Controller\Track\SubmitSurveyResponse - same ownership check
 * (SurveyPrompt::belongsTo()), same "never overwrite an already-answered prompt" rule, and the
 * same score-vs-response_text branching on the prompt's own question_type (see ADR 0064 - this
 * mutation originally only ever handled a legacy NPS score; the REST controller already had the
 * generic_survey branch from ADR 0061).
 */
class SubmitSurveyResponse implements ResolverInterface
{
    public function __construct(
        private readonly SurveyPromptFactory $surveyPromptFactory,
        private readonly SurveyPromptResource $surveyPromptResource,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{surveyId?: int, score?: int, responseText?: string, visitorId?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $promptId = isset($args['surveyId']) ? (int) $args['surveyId'] : 0;
        if ($promptId <= 0) {
            return ['ok' => false];
        }

        $visitorId = $args['visitorId'] ?? '';
        $customerId = $this->customerIdResolver->resolveCustomerId($context);

        $prompt = $this->surveyPromptFactory->create();
        $this->surveyPromptResource->load($prompt, $promptId);

        if (!$prompt->getId()
            || $prompt->getRespondedAt() !== null
            || !$prompt->belongsTo($customerId, $visitorId)
        ) {
            return ['ok' => false];
        }

        if ($prompt->getQuestionType() === null) {
            // Legacy nps_survey prompt - unchanged 0-10 score validation.
            $score = $args['score'] ?? null;
            if (!is_numeric($score)) {
                return ['ok' => false];
            }

            $score = (int) $score;
            if ($score < 0 || $score > 10) {
                return ['ok' => false];
            }

            $prompt->setScore($score);
        } else {
            $responseText = trim((string) ($args['responseText'] ?? ''));
            if ($responseText === '') {
                return ['ok' => false];
            }

            if ($prompt->getQuestionType() === 'choice') {
                $choices = array_map('trim', explode(',', (string) $prompt->getChoices()));
                if (!in_array($responseText, $choices, true)) {
                    return ['ok' => false];
                }
            }

            $prompt->setResponseText($responseText);
        }

        $prompt->setRespondedAt(date('Y-m-d H:i:s'));
        $this->surveyPromptResource->save($prompt);

        return ['ok' => true];
    }
}
