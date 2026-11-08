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
 * (SurveyPrompt::belongsTo()) and same "never overwrite an already-answered prompt" rule.
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
        $score = $args['score'] ?? null;

        if ($promptId <= 0 || !is_numeric($score)) {
            return ['ok' => false];
        }

        $score = (int) $score;
        if ($score < 0 || $score > 10) {
            return ['ok' => false];
        }

        $visitorId = isset($args['visitorId']) && is_string($args['visitorId']) ? $args['visitorId'] : '';
        $customerId = $this->customerIdResolver->resolveCustomerId($context);

        $prompt = $this->surveyPromptFactory->create();
        $this->surveyPromptResource->load($prompt, $promptId);

        if (!$prompt->getId()
            || $prompt->getRespondedAt() !== null
            || !$prompt->belongsTo($customerId, $visitorId)
        ) {
            return ['ok' => false];
        }

        $prompt->setScore($score);
        $prompt->setRespondedAt(date('Y-m-d H:i:s'));
        $this->surveyPromptResource->save($prompt);

        return ['ok' => true];
    }
}
