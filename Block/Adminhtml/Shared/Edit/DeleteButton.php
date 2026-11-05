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
            // deleteConfirm's 2-argument form (no postData) navigates via a plain GET
            // (mage/adminhtml/globals.js's setLocation()) - every entity's Delete controller in
            // this module implements HttpPostActionInterface only, so app/etc/di.xml's HTTP
            // method map rejects that GET before it ever reaches the controller, 404ing every
            // edit-form Delete button in this module. The 3-argument form (mage/dataPost.js)
            // builds and submits a real POST form instead, with form_key auto-attached - same
            // pattern Magento\Backend\Block\Widget\Form\Container::_construct() uses for core's
            // own generic Delete button. Found by actually clicking Delete in a real browser.
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __($this->confirmMessage),
                $this->getUrl('*/*/delete', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 20,
        ];
    }
}
