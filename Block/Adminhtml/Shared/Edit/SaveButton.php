<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Was eight copies differing only by label and form target — each entity's save button is now
 * a virtualType of this class in etc/adminhtml/di.xml, referenced by its virtualType name from
 * the entity's ui_component form XML.
 */
class SaveButton extends GenericButton implements ButtonProviderInterface
{
    public function __construct(
        Context $context,
        private readonly string $label,
        private readonly string $targetName
    ) {
        parent::__construct($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __($this->label),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            [
                                'targetName' => $this->targetName,
                                'actionName' => 'save',
                                'params' => [false],
                            ],
                        ],
                    ],
                ],
            ],
            'sort_order' => 90,
        ];
    }
}
