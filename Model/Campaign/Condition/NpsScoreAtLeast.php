<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;

/**
 * Params: {"threshold": 9} — same dedicated "threshold" field ScoreAtLeast already uses, so no
 * new admin form field is needed. Context must include "customer_id" — there is no visitor_id
 * path here,
 * since an anonymous visitor's survey response can't be reliably re-matched to a later
 * customer_id-scoped campaign/segment evaluation the way a logged-in customer's can.
 *
 * Reads only the customer's single most recent *answered* ordo_survey_prompt row (Model\
 * ResourceModel\SurveyPrompt\Collection::addLatestResponseFilter()) — a customer who was never
 * asked, or hasn't answered yet, simply fails this condition rather than throwing.
 */
class NpsScoreAtLeast implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

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

        return $score !== null && $score >= (int) $threshold;
    }

    /**
     * Set-level counterpart to isSatisfied(): every customer whose single most recent
     * *answered* prompt scores at or above the threshold — the self-join picks each
     * customer's latest responded_at first, so an old high score never counts for a customer
     * whose newest answer dipped below the threshold.
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
                ->where('sp.score >= ?', (int) $threshold)
                ->distinct()
        );

        return array_map('intval', $ids);
    }
}
