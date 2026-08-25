<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Ui\Component\Control\Container\ToolbarButtonInterface;

class SaveAndContinueButton extends GenericButton implements ToolbarButtonInterface
{
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
                                'targetName' => 'ordo_campaign_form.ordo_campaign_form',
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
