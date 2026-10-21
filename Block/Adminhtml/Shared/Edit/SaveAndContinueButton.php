<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Was eight copies differing only by form target — each entity's save-and-continue button is
 * now a virtualType of this class in etc/adminhtml/di.xml, referenced by its virtualType name
 * from the entity's ui_component form XML.
 */
class SaveAndContinueButton extends GenericButton implements ButtonProviderInterface
{
    public function __construct(
        Context $context,
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
            'label' => __('Save & Continue Edit'),
            'class' => 'save',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            [
                                'targetName' => $this->targetName,
                                'actionName' => 'save',
                                'params' => [true, ['back' => 'edit']],
                            ],
                        ],
                    ],
                ],
            ],
            'sort_order' => 80,
        ];
    }
}
