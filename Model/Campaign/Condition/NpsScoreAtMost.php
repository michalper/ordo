<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;

/**
 * The "detractor" mirror of NpsScoreAtLeast - same "threshold" dedicated field, same single
 * most-recent-*answered*-prompt read (Model\ResourceModel\SurveyPrompt\Collection::
 * addLatestResponseFilter()), flipped comparison (<=, not >=). See that class's own docblock for
 * why context must carry "customer_id" and why a never-asked/never-answered customer simply
 * fails this condition rather than throwing - identical reasoning applies here.
 */
class NpsScoreAtMost implements ConditionInterface, BulkResolvableConditionInterface
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

        return $score !== null && $score <= (int) $threshold;
    }

    /**
     * Set-level counterpart to isSatisfied() - see NpsScoreAtLeast::resolveCustomerIds()'s own
     * docblock for the self-join reasoning, identical here aside from the flipped comparison.
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
                ->where('sp.score <= ?', (int) $threshold)
                ->distinct()
        );

        return array_map('intval', $ids);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'threshold', 'label' => (string) __('Maximum NPS score')],
        ];
    }
}
