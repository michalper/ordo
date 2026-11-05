<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Magento\Email\Model\Template\Config as EmailTemplateConfig;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every email template `Magento\Framework\Mail\Template\TransportBuilder::setTemplateIdentifier()`
 * can actually resolve - the send_email campaign action's own `template` field picker. Two
 * sources, both real: every XML-registered default template across every module
 * (`Magento\Email\Model\Template\Config::getAvailableTemplates()` - not scoped to this module,
 * since `template` can legitimately reference any Magento-registered default, e.g. a core
 * "New Order" template), plus every admin-saved/customized DB row
 * (`Magento\Email\Model\ResourceModel\Template\Collection::toOptionArray()`, Magento core's own
 * method for this exact lookup - mirrors `Magento\Config\Model\Config\Source\Email\Template`'s
 * own two-source shape, which this field can't reuse directly since that class is hardcoded to
 * one system.xml path's own single default template, not a general picker).
 */
class EmailTemplateOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly EmailTemplateConfig $emailTemplateConfig,
        private readonly MagentoTemplateCollectionFactory $magentoTemplateCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string|int, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->emailTemplateConfig->getAvailableTemplates() as $template) {
            $options[] = ['value' => (string) $template['value'], 'label' => (string) $template['label']];
        }

        foreach ($this->magentoTemplateCollectionFactory->create()->toOptionArray() as $template) {
            $options[] = ['value' => (int) $template['value'], 'label' => (string) $template['label']];
        }

        return $options;
    }
}
