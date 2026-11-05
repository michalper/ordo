<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\WhatsAppTemplate\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;

/**
 * Visible for any already-saved template regardless of its current status - GenericButton (this
 * class's own base, shared by every entity's edit toolbar in this module) only exposes
 * entity_id, not the entity's own status, so this doesn't try to hide itself for an
 * already-pending/approved template. Controller\Adminhtml\WhatsAppTemplate\SubmitForReview itself
 * now guards against re-submitting one of those (not actually harmless - Meta may treat a second
 * registration of the same content as a duplicate/reject it), so clicking this on a pending/
 * approved template shows an error message instead of re-calling Meta.
 */
class SubmitForReviewButton extends GenericButton implements ButtonProviderInterface
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
            'label' => __('Submit for Review'),
            'class' => 'action-secondary',
            // deleteConfirm's 3-argument form (mage/dataPost.js) submits a real POST with
            // form_key auto-attached - the 2-argument form navigates via a plain GET
            // (mage/adminhtml/globals.js's setLocation()), which 404s because SubmitForReview
            // implements HttpPostActionInterface only (app/etc/di.xml's HTTP method map rejects
            // the GET before it dispatches). This button had that exact bug - the comment here
            // used to claim the 2-argument form already POSTed, which isn't true; see
            // Block\Adminhtml\Shared\Edit\DeleteButton's own docblock for the full story.
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Submit this template to Meta for approval?'),
                $this->getUrl('*/*/submitforreview', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 40,
        ];
    }
}
