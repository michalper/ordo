<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ScoreRule\Website;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website as ScoreRuleWebsiteResource;
use Ordo\Automation\Model\ScoreRuleWebsite as ScoreRuleWebsiteModel;

/**
 * @method \ArrayIterator<int, ScoreRuleWebsiteModel> getIterator()
 * @method ScoreRuleWebsiteModel[] getItems()
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ScoreRuleWebsiteModel::class, ScoreRuleWebsiteResource::class);
    }

    public function addScoreRuleFilter(int $scoreRuleId): self
    {
        $this->addFieldToFilter('score_rule_id', $scoreRuleId);
        return $this;
    }

    /**
     * Every website row for every given rule in one query - ScoreRuleEvaluator's own
     * website-scope guard uses this to check all enabled rules at once, same "one query, not N"
     * reasoning as Campaign\Website\Collection::addCampaignIdsFilter().
     *
     * @param int[] $scoreRuleIds
     */
    public function addScoreRuleIdsFilter(array $scoreRuleIds): self
    {
        $this->addFieldToFilter('score_rule_id', ['in' => $scoreRuleIds]);
        return $this;
    }
}
