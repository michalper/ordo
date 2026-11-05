<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\EmailTemplateDraft\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;

/**
 * Publish has a real, immediate, irreversible-without-another-publish side effect on the live
 * template send_email reads - deleteConfirm (this module's established confirm+POST pattern,
 * see every Delete button and WhatsAppTemplate\Edit\SubmitForReviewButton) rather than a plain
 * save-style submit.
 */
class PublishButton extends GenericButton implements ButtonProviderInterface
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
            'label' => __('Publish'),
            'class' => 'action-secondary',
            // The 3-argument deleteConfirm() form (mage/dataPost.js) submits a real POST with
            // form_key auto-attached - needed because Publish implements HttpPostActionInterface
            // only, so the 2-argument form's plain GET navigation 404s before reaching the
            // controller (app/etc/di.xml's HTTP method map). See DeleteButton's own docblock.
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Publish this draft onto the live template? This immediately changes what send_email sends.'),
                $this->getUrl('*/*/publish', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 40,
        ];
    }
}
