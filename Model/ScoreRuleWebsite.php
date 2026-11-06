<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website as ScoreRuleWebsiteResource;

/**
 * Admin-only, same as SegmentWebsite - no Api\Data\ScoreRuleWebsiteInterface (ScoreRule itself
 * has no service-contract interface either, see its own docblock).
 */
class ScoreRuleWebsite extends AbstractModel
{
    use HasEntityIdGetterTrait;

    protected function _construct(): void
    {
        $this->_init(ScoreRuleWebsiteResource::class);
    }

    public function getScoreRuleId(): int
    {
        return (int) $this->getData('score_rule_id');
    }

    public function setScoreRuleId(int $scoreRuleId): self
    {
        $this->setData('score_rule_id', $scoreRuleId);
        return $this;
    }

    public function getWebsiteId(): int
    {
        return (int) $this->getData('website_id');
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData('website_id', $websiteId);
        return $this;
    }
}
