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
            // deleteConfirm (Magento core, mage/adminhtml/tools.js) is this codebase's own
            // established POST-with-form-key helper for a mutating admin action - this one makes
            // a real outbound Graph API call and writes the result, so a plain GET navigation
            // isn't safe here either (see SubmitForReviewButton's own comment).
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s')",
                __('Refresh this template\'s status from Meta?'),
                $this->getUrl('*/*/refreshstatus', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 30,
        ];
    }
}
