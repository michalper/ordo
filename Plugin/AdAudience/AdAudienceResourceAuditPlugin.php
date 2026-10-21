<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\AdAudience;

use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;

/**
 * See Plugin\Shared\AbstractResourceAuditPlugin's own docblock for why this is a real subclass,
 * not a virtualType.
 */
class AdAudienceResourceAuditPlugin extends AbstractResourceAuditPlugin
{
    protected function entityType(): string
    {
        return 'ad_audience';
    }

    protected function auditedFields(): array
    {
        return ['name', 'segment_id', 'platform', 'enabled'];
    }
}
