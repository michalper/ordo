<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\SurveyPrompt;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\ResourceModel\VisitorTargetFilterTrait;
use Ordo\Automation\Model\SurveyPrompt as SurveyPromptModel;

class Collection extends AbstractCollection
{
    use VisitorTargetFilterTrait;

    protected function _construct(): void
    {
        $this->_init(SurveyPromptModel::class, SurveyPromptResource::class);
    }

    private function claimColumn(): string
    {
        return 'delivered_at';
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
