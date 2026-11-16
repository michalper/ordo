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
            'on_click' => $this->confirmedPostOnClick(
                __('Publish this draft onto the live template? This immediately changes what send_email sends.'),
                '*/*/publish'
            ),
            'sort_order' => 40,
        ];
    }
}
