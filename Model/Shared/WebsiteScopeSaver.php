<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Shared;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Shared by CampaignSaveProcessor::saveWebsites()/SegmentSaveProcessor::saveWebsites() - both
 * delete every existing *_website child row for the owning entity and re-insert one row per
 * distinct, positive posted website id, differing only in which factory/resource/collection and
 * which owner-id column name they use. No rows at all means unscoped (matches every website) -
 * see docs/adr/0014 - so an empty posted selection (the common case; most installs are
 * single-website) correctly leaves zero website rows rather than writing anything.
 */
class WebsiteScopeSaver
{
    /**
     * @param AbstractCollection<AbstractModel> $existingWebsites already filtered to the one
     *   owning entity
     * @param callable(): AbstractModel $createWebsite
     * @param array<int, string|int> $websiteIds
     */
    public function save(
        AbstractCollection $existingWebsites,
        AbstractDb $websiteResource,
        callable $createWebsite,
        string $ownerIdField,
        int $ownerId,
        array $websiteIds
    ): void {
        foreach ($existingWebsites as $existing) {
            $websiteResource->delete($existing);
        }

        $seen = [];
        foreach ($websiteIds as $websiteId) {
            $websiteId = (int) $websiteId;
            if ($websiteId <= 0 || isset($seen[$websiteId])) {
                continue;
            }
            $seen[$websiteId] = true;

            $website = $createWebsite();
            $website->setData([
                $ownerIdField => $ownerId,
                'website_id' => $websiteId,
            ]);
            $websiteResource->save($website);
        }
    }
}
