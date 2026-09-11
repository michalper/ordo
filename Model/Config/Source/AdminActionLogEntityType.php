<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The Admin Action Log grid's "Entity" column filter - matches the fixed set of entity types
 * currently audited (Plugin\Campaign\CampaignSaveProcessorAuditPlugin,
 * Plugin\Segment\SegmentSaveProcessorAuditPlugin). Extend this alongside adding a new audited
 * entity's own plugin.
 */
class AdminActionLogEntityType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'campaign', 'label' => __('Campaign')],
            ['value' => 'segment', 'label' => __('Segment')],
        ];
    }
}
