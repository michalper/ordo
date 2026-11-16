<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Was eight copies differing only by the delete-confirm message — each entity's delete button
 * is now a virtualType of this class in etc/adminhtml/di.xml, referenced by its virtualType
 * name from the entity's ui_component form XML.
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    public function __construct(
        Context $context,
        private readonly string $confirmMessage
    ) {
        parent::__construct($context);
    }

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
            'on_click' => $this->confirmedPostOnClick(__($this->confirmMessage), '*/*/delete'),
            'sort_order' => 20,
        ];
    }
}
