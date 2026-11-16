<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Psr\Log\LoggerInterface;

/**
 * Extracted after a design-review pass found this exact 8-line block, byte-for-byte identical,
 * duplicated across three campaign actions (ShowPopup, Notify, NpsSurvey) - each targets
 * whichever identifier the triggering context actually has: context["customer_id"] for
 * customer-only triggers (order_placed, tag_added, ...), context["visitor_id"] for the anonymous
 * visitor_tag_added trigger. nullableString() (trim-to-null for optional string params) was
 * separately duplicated in two of those three. No behavior change versus the original inline
 * code in any of the three - same edge cases (customer_id present but <= 0 treated as absent,
 * visitor_id present but empty string treated as absent).
 */
class ContextTargetResolver
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolveCustomerOrVisitor(array $context): ContextTarget
    {
        $customerId = isset($context['customer_id']) ? (int) $context['customer_id'] : null;
        $customerId = ($customerId !== null && $customerId > 0) ? $customerId : null;

        $visitorId = isset($context['visitor_id']) ? (string) $context['visitor_id'] : null;
        $visitorId = ($visitorId !== null && $visitorId !== '') ? $visitorId : null;

        return new ContextTarget($customerId, $visitorId);
    }

    public function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Shared validation gate for every action that targets a customer/visitor and requires a
     * non-blank text param to go with it (ShowPopup/Notify's "headline", NpsSurvey/
     * GenericSurvey's "question") - logs its own action/field-specific error and returns null
     * when either the target or the field is missing, so the caller bails before building/
     * saving anything. Same two error messages each of those four classes used to write out by
     * hand, byte-for-byte, parameterized here by $actionLabel/$fieldName.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     * @return array{target: ContextTarget, value: string}|null
     */
    public function resolveTargetAndRequiredField(
        array $context,
        array $params,
        string $fieldName,
        string $actionLabel
    ): ?array {
        $target = $this->resolveCustomerOrVisitor($context);
        $value = trim((string) ($params[$fieldName] ?? ''));

        if ($target->isEmpty()) {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action has no customer_id or visitor_id in context to target.',
                $actionLabel
            ));
            return null;
        }

        if ($value === '') {
            $this->logger->error(sprintf('Ordo_Automation: %s action is missing a %s.', $actionLabel, $fieldName));
            return null;
        }

        return ['target' => $target, 'value' => $value];
    }
}
