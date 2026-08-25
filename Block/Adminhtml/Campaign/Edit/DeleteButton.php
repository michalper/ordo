<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Ui\Component\Control\Container\ToolbarButtonInterface;

class DeleteButton extends GenericButton implements ToolbarButtonInterface
{
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
                __('Are you sure you want to delete this campaign?'),
                $this->getUrl('*/*/delete', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 20,
        ];
    }
}
