<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ChurnRisk\ChurnRiskCalculator;

/**
 * Params: {"churn_risk_ratio_at_least": "1.2"} — satisfied when the customer's own churn-risk
 * ratio (ChurnRiskCalculator: elapsed days since their last order, divided by the median
 * interval between their own past orders) is at least this. Generalizes
 * Campaign\Condition\ReorderCycleAtRisk's "drifting from their own baseline" signal to every
 * customer with at least 2 orders, not just customers with an already-detected, SKU-specific
 * reorder cycle (ReorderCycleAtRisk fails closed for an irregular or mixed-basket buyer, since
 * Cron\CalculateReorderCycle never writes a row for them at all).
 *
 * The param/field name is deliberately its own dedicated name, not ReorderCycleAtRisk's shared
 * "ratio_at_least" — ParamFieldsNormalizer reads/writes `$row[$field['name']]` directly, so a
 * dedicated field's name, its getParamFields() entry, and its saved params key must all be the
 * same string; reusing "ratio_at_least" here would mean reusing that field's own classic-editor
 * `<field>` element too, which has a fixed, non-dynamic label ("Reorder Cycle Drift Ratio...")
 * that would be wrong for this condition.
 */
class ChurnRiskAtLeast implements ConditionInterface, BulkResolvableConditionInterface
{
    private const string PARAM_RATIO_AT_LEAST = 'churn_risk_ratio_at_least';

    public function __construct(
        private readonly ChurnRiskCalculator $churnRiskCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $ratioAtLeast = $params[self::PARAM_RATIO_AT_LEAST] ?? null;

        if ($customerId <= 0 || $ratioAtLeast === null || !is_numeric($ratioAtLeast)) {
            return false;
        }

        $ratio = $this->churnRiskCalculator->getChurnRiskRatioForCustomer($customerId);

        return $ratio !== null && $ratio >= (float) $ratioAtLeast;
    }

    public function resolveCustomerIds(array $params): array
    {
        $ratioAtLeast = $params[self::PARAM_RATIO_AT_LEAST] ?? null;

        if ($ratioAtLeast === null || !is_numeric($ratioAtLeast)) {
            return [];
        }

        $threshold = (float) $ratioAtLeast;
        $matching = [];

        foreach ($this->churnRiskCalculator->getChurnRiskRatiosForAllCustomers() as $customerId => $ratio) {
            if ($ratio >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => self::PARAM_RATIO_AT_LEAST,
                'label' => (string) __('Churn risk ratio, at least (1.0 = exactly on their own cadence)'),
            ],
        ];
    }
}
