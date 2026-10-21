<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\ScoreRule;

use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;

/**
 * See Plugin\Shared\AbstractResourceAuditPlugin's own docblock for why this is a real subclass,
 * not a virtualType.
 */
class ScoreRuleResourceAuditPlugin extends AbstractResourceAuditPlugin
{
    protected function entityType(): string
    {
        return 'score_rule';
    }

    protected function auditedFields(): array
    {
        return ['attribute_code', 'operator', 'value', 'points', 'enabled'];
    }
}
