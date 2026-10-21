<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\FreeGiftOffer;

use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;

/**
 * See Plugin\Shared\AbstractResourceAuditPlugin's own docblock for why this is a real subclass,
 * not a virtualType.
 */
class FreeGiftOfferResourceAuditPlugin extends AbstractResourceAuditPlugin
{
    protected function entityType(): string
    {
        return 'free_gift_offer';
    }

    protected function auditedFields(): array
    {
        return ['name', 'enabled'];
    }
}
