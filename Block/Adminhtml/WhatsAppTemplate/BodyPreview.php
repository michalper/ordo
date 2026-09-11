<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Block\Template;

/**
 * Live character-counter + rendered-preview panel for the WhatsApp Template Edit page's
 * "Body Text" field (view/adminhtml/ui_component/ordo_whatsapptemplate_form.xml), closing the
 * ROADMAP.md gap where that field was a raw textarea with no feedback before a real (costly)
 * Meta review submission. whatsapp-template-body-preview.js does the actual live work - this
 * block just supplies the max length so it's declared once (matching the UI-component form's own
 * `max_text_length` validation rule) instead of hardcoded twice.
 */
class BodyPreview extends Template
{
    /**
     * Meta's own documented WhatsApp template body character limit - same value the UI-component
     * form's `max_text_length` validation rule uses.
     */
    public const int BODY_MAX_LENGTH = 1024;

    public function getBodyMaxLength(): int
    {
        return self::BODY_MAX_LENGTH;
    }
}
