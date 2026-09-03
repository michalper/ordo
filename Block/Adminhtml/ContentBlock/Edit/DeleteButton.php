<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\ContentBlock\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;

class DeleteButton extends GenericButton implements ButtonProviderInterface
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
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s')",
                __('Are you sure you want to delete this content block?'),
                $this->getUrl('*/*/delete', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 20,
        ];
    }
}
