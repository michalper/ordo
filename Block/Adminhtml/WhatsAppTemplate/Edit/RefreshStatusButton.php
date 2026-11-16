<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\WhatsAppTemplate\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;

class RefreshStatusButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->getEntityId()) {
            return [];
        }

        return [
            'label' => __('Refresh Status from Meta'),
            'class' => 'action-secondary',
            'on_click' => $this->confirmedPostOnClick(
                __('Refresh this template\'s status from Meta?'),
                '*/*/refreshstatus'
            ),
            'sort_order' => 30,
        ];
    }
}
