<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

/**
 * Every existing campaign, by id/name - the picker for the message_engaged condition's
 * campaign_id field. Mirrors SegmentOptions.php exactly, including listing ALL campaigns, not
 * just enabled ones (a disabled campaign's past sends are still real history to target).
 */
class CampaignOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->campaignCollectionFactory->create() as $campaign) {
            /** @var \Ordo\Automation\Model\Campaign $campaign */
            $options[] = ['value' => (int) $campaign->getEntityId(), 'label' => $campaign->getName()];
        }

        return $options;
    }
}
