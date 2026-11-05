<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use Ordo\Automation\Model\WhatsAppTemplate;

/**
 * Every APPROVED template, by id/name — the send_whatsapp campaign action's own template_id
 * field picker. Deliberately excludes draft/pending/rejected/disabled templates: Meta's own API
 * rejects a send using anything but an approved template, so offering one here would just be a
 * guaranteed-to-fail choice.
 *
 * A template with its own `placeholder_legend` filled in has that legend appended to its label
 * here - the only place that mapping is surfaced to whoever is configuring a send_whatsapp
 * action, since this same option list feeds that action's template_id select
 * (Block\Adminhtml\Campaign\Edit\Flow::getWhatsAppTemplateOptions()). Raised directly by a user:
 * without this, nothing tells the person wiring up a campaign what {{1}}, {{2}}, ... actually
 * mean for the template they just picked.
 */
class WhatsAppTemplateOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly WhatsAppTemplateCollectionFactory $whatsAppTemplateCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->whatsAppTemplateCollectionFactory->create();
        $collection->addApprovedFilter();

        $options = [];
        foreach ($collection as $template) {
            /** @var WhatsAppTemplate $template */
            $legend = $template->getPlaceholderLegend();
            $label = $legend !== null && trim($legend) !== ''
                ? $template->getName() . ' (' . trim($legend) . ')'
                : $template->getName();
            $options[] = ['value' => (int) $template->getEntityId(), 'label' => $label];
        }

        return $options;
    }
}
