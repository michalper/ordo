<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;

/**
 * Shared by NpsScoreAtLeast/NpsScoreAtMost - extracted after SonarCloud's new-code duplication
 * gate flagged the two as near-identical (same "threshold" dedicated field, same single
 * most-recent-*answered*-prompt read via SurveyPrompt\Collection::addLatestResponseFilter(),
 * same self-join shape for the set-level resolveCustomerIds(), differing only in the comparison
 * direction). Each subclass supplies only that comparison, never a raw SQL fragment - the
 * operator string sqlComparisonOperator() returns is a fixed literal chosen from a small,
 * hardcoded subclass-controlled enum ('>=' or '<='), never derived from $params or any other
 * caller-supplied value, so embedding it directly in the query below carries no injection risk.
 *
 * Context must include "customer_id" - there is no visitor_id path here, since an anonymous
 * visitor's survey response can't be reliably re-matched to a later customer_id-scoped campaign/
 * segment evaluation the way a logged-in customer's can. A customer who was never asked, or
 * hasn't answered yet, simply fails either condition rather than throwing.
 */
abstract class AbstractNpsScoreThresholdCondition implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    abstract protected function scoreSatisfiesThreshold(int $score, int $threshold): bool;

    /**
     * @return '>='|'<='
     */
    abstract protected function sqlComparisonOperator(): string;

    abstract protected function thresholdFieldLabel(): string;

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $threshold = $params['threshold'] ?? null;

        if ($customerId <= 0 || !is_numeric($threshold)) {
            return false;
        }

        $collection = $this->surveyPromptCollectionFactory->create();
        $collection->addLatestResponseFilter($customerId);

        $latest = $collection->getFirstItem();
        $score = $latest->getScore();

        return $score !== null && $this->scoreSatisfiesThreshold((int) $score, (int) $threshold);
    }

    /**
     * Set-level counterpart to isSatisfied(): every customer whose single most recent
     * *answered* prompt satisfies the threshold - the self-join picks each customer's latest
     * responded_at first, so an old matching score never counts for a customer whose newest
     * answer no longer satisfies it.
     */
    public function resolveCustomerIds(array $params): array
    {
        $threshold = $params['threshold'] ?? null;

        if ($threshold === null || !is_numeric($threshold)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_survey_prompt');

        $latestPerCustomer = $connection->select()
            ->from($table, ['customer_id', 'max_responded_at' => new \Zend_Db_Expr('MAX(responded_at)')])
            ->where('customer_id IS NOT NULL')
            ->where('responded_at IS NOT NULL')
            ->group('customer_id');

        $ids = $connection->fetchCol(
            $connection->select()
                ->from(['sp' => $table], 'sp.customer_id')
                ->join(
                    ['latest' => $latestPerCustomer],
                    'latest.customer_id = sp.customer_id AND latest.max_responded_at = sp.responded_at',
                    []
                )
                ->where('sp.score ' . $this->sqlComparisonOperator() . ' ?', (int) $threshold)
                ->distinct()
        );

        return array_map('intval', $ids);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'threshold', 'label' => $this->thresholdFieldLabel()],
        ];
    }
}
