<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\SurveyPrompt;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPrompt as SurveyPromptModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SurveyPromptModel::class, SurveyPromptResource::class);
    }

    /**
     * Every not-yet-delivered, not-yet-expired survey prompt queued for either this customer or
     * this visitor_id — same both-identifiers reasoning as PendingPopup\Collection::
     * addTargetFilter().
     */
    public function addTargetFilter(?int $customerId, ?string $visitorId, string $now): self
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

        $this->addFieldToFilter('delivered_at', ['null' => true]);
        $this->addFieldToFilter(
            ['expires_at', 'expires_at'],
            [['null' => true], ['gt' => $now]]
        );
        $this->setOrder('entity_id', self::SORT_ORDER_ASC);

        return $this;
    }

    /**
     * The most recent *answered* prompt for this customer — Model\Campaign\Condition\
     * NpsScoreAtLeast reads only this, never an unanswered/still-pending one.
     */
    public function addLatestResponseFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->addFieldToFilter('responded_at', ['notnull' => true]);
        $this->setOrder('responded_at', self::SORT_ORDER_DESC);
        $this->setPageSize(1);

        return $this;
    }
}
