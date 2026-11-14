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

    /**
     * Same shape as addLatestResponseFilter() above, but scoped to one literal question text -
     * Model\Campaign\Condition\SurveyResponseEquals needs this and NpsScoreAtLeast doesn't,
     * because NPS has exactly one implicit question concept (any nps_survey prompt counts,
     * whatever its literal wording) while a generic_survey answer is only meaningful for the
     * specific question it was asked under. A customer's latest answered row *overall* could be
     * for a different question entirely, so the question filter has to apply before picking the
     * latest row, not after.
     */
    public function addLatestResponseFilterForQuestion(int $customerId, string $question): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->addFieldToFilter('question', ['eq' => $question]);
        $this->addFieldToFilter('responded_at', ['notnull' => true]);
        $this->setOrder('responded_at', self::SORT_ORDER_DESC);
        $this->setPageSize(1);

        return $this;
    }
}
