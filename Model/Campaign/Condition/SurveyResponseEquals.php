<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;

/**
 * Params: {"question": "...", "response_text": "..."} - the zero-party-data counterpart to
 * NpsScoreAtLeast, for a generic_survey (text/choice) answer instead of an NPS score. Context
 * must include "customer_id" - same no-visitor_id-path reasoning as NpsScoreAtLeast (an
 * anonymous visitor's answer can't be reliably re-matched to a later customer_id-scoped
 * campaign/segment evaluation).
 *
 * Unlike NpsScoreAtLeast, this reads the customer's most recent *answered* row for this specific
 * question text (Model\ResourceModel\SurveyPrompt\Collection::addLatestResponseFilterForQuestion())
 * rather than their most recent answered row overall - see that method's own docblock for why
 * NPS doesn't need that distinction and this condition does.
 */
class SurveyResponseEquals implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $question = trim((string) ($params['question'] ?? ''));
        $expected = trim((string) ($params['response_text'] ?? ''));

        if ($customerId <= 0 || $question === '' || $expected === '') {
            return false;
        }

        $collection = $this->surveyPromptCollectionFactory->create();
        $collection->addLatestResponseFilterForQuestion($customerId, $question);

        $latest = $collection->getFirstItem();
        $responseText = $latest->getResponseText();

        return $responseText !== null && $responseText === $expected;
    }

    /**
     * Set-level counterpart to isSatisfied(): every customer whose single most recent *answered*
     * row for this question equals the expected response_text - the self-join picks each
     * customer's latest responded_at for this question first, same reasoning as
     * NpsScoreAtLeast::resolveCustomerIds(), scoped to the question the same way
     * addLatestResponseFilterForQuestion() is.
     */
    public function resolveCustomerIds(array $params): array
    {
        $question = trim((string) ($params['question'] ?? ''));
        $expected = trim((string) ($params['response_text'] ?? ''));

        if ($question === '' || $expected === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_survey_prompt');

        $latestPerCustomer = $connection->select()
            ->from($table, ['customer_id', 'max_responded_at' => new \Zend_Db_Expr('MAX(responded_at)')])
            ->where('customer_id IS NOT NULL')
            ->where('responded_at IS NOT NULL')
            ->where('question = ?', $question)
            ->group('customer_id');

        $ids = $connection->fetchCol(
            $connection->select()
                ->from(['sp' => $table], 'sp.customer_id')
                ->join(
                    ['latest' => $latestPerCustomer],
                    'latest.customer_id = sp.customer_id AND latest.max_responded_at = sp.responded_at',
                    []
                )
                ->where('sp.question = ?', $question)
                ->where('sp.response_text = ?', $expected)
                ->distinct()
        );

        return array_map('intval', $ids);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'question', 'label' => (string) __('Question')],
            ['name' => 'response_text', 'label' => (string) __('Expected answer')],
        ];
    }
}
