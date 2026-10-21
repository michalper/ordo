<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\ContentBlock;

use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;

/**
 * See Plugin\Shared\AbstractResourceAuditPlugin's own docblock for why this is a real subclass,
 * not a virtualType.
 */
class ContentBlockResourceAuditPlugin extends AbstractResourceAuditPlugin
{
    protected function entityType(): string
    {
        return 'content_block';
    }

    protected function auditedFields(): array
    {
        return ['name', 'identifier', 'type', 'enabled'];
    }
}
