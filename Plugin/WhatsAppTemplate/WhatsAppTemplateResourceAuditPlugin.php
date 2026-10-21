<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\WhatsAppTemplate;

use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;

/**
 * See Plugin\Shared\AbstractResourceAuditPlugin's own docblock for why this is a real subclass,
 * not a virtualType.
 */
class WhatsAppTemplateResourceAuditPlugin extends AbstractResourceAuditPlugin
{
    protected function entityType(): string
    {
        return 'whatsapp_template';
    }

    protected function auditedFields(): array
    {
        return ['name', 'meta_template_name', 'category', 'language', 'body_text', 'status'];
    }
}
