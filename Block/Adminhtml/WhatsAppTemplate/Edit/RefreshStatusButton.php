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
            // deleteConfirm's 3-argument form (mage/dataPost.js) submits a real POST with
            // form_key auto-attached - needed because RefreshStatus implements
            // HttpPostActionInterface only, so the 2-argument form's plain GET navigation
            // (mage/adminhtml/globals.js's setLocation()) 404s before ever reaching the
            // controller (app/etc/di.xml's HTTP method map). See
            // Block\Adminhtml\Shared\Edit\DeleteButton's own docblock for the full story - this
            // button had the exact same bug, found and fixed separately.
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Refresh this template\'s status from Meta?'),
                $this->getUrl('*/*/refreshstatus', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 30,
        ];
    }
}
