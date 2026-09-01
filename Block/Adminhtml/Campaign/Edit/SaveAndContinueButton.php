<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;

class SaveAndContinueButton extends GenericButton implements ButtonProviderInterface
{
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
