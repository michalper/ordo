<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Campaign as CampaignModel;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignModel::class, CampaignResource::class);
    }

    public function addEnabledFilter(): self
    {
        $this->addFieldToFilter('enabled', 1);
        return $this;
    }

    /**
     * @param int[] $campaignIds
     */
    public function addIdsFilter(array $campaignIds): self
    {
        $this->addFieldToFilter('entity_id', ['in' => $campaignIds]);
        // A "WHERE entity_id IN (...)" query has no guaranteed row order on its own — MySQL
        // usually returns clustered-index (entity_id ascending) order in practice, but nothing
        // enforces it. Model/CampaignDispatcher.php's dispatch() evaluates every campaign
        // matched to one trigger in a single foreach, and its own docblock documents ascending
        // entity_id as the evaluation order (older campaigns' actions - e.g. a tag - can be
        // seen by a younger campaign's conditions in that same pass, not the reverse). Make
        // that order explicit instead of relying on undocumented MySQL/InnoDB behavior.
        $this->setOrder('entity_id', self::SORT_ORDER_ASC);
        return $this;
    }
}
