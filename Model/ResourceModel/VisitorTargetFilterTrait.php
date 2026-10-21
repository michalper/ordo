<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

/**
 * Shared addTargetFilter() for the on-site "message to a visitor or customer" collections
 * (Notification, PendingPopup, SurveyPrompt) — each row is keyed by the nullable
 * customer_id/visitor_id pair (Model\VisitorIdentityFieldsTrait), and a logged-in customer whose
 * browser also carries the visitor_id cookie from before they logged in can still be targeted
 * either way, so the filter checks both identifiers rather than just customer_id once logged in.
 *
 * Only the claim column differs per collection: delivered_at for PendingPopup/SurveyPrompt
 * (claimed the moment a row is handed out), read_at for Notification (a notification stays in
 * the result set across every poll until explicitly dismissed — see
 * Controller\Track\DismissNotification).
 */
trait VisitorTargetFilterTrait
{
    abstract private function claimColumn(): string;

    public function addTargetFilter(?int $customerId, ?string $visitorId, string $now): self
    {
        $this->addVisitorIdentityFilter($customerId, $visitorId);

        $this->addFieldToFilter($this->claimColumn(), ['null' => true]);
        $this->addFieldToFilter(
            ['expires_at', 'expires_at'],
            [['null' => true], ['gt' => $now]]
        );
        $this->setOrder('entity_id', self::SORT_ORDER_ASC);

        return $this;
    }

    private function addVisitorIdentityFilter(?int $customerId, ?string $visitorId): void
    {
        $conditions = [];
        if ($customerId !== null) {
            $conditions[] = ['field' => 'customer_id', 'condition' => ['eq' => $customerId]];
        }
        if ($visitorId !== null && $visitorId !== '') {
            $conditions[] = ['field' => 'visitor_id', 'condition' => ['eq' => $visitorId]];
        }

        if ($conditions !== []) {
            $this->addFieldToFilter(
                array_column($conditions, 'field'),
                array_column($conditions, 'condition')
            );
        }
    }
}
