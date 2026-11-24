<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Approval;

use Ordo\Automation\Model\RandomTokenGenerator;

/**
 * Generates the order-approval decision token - see RandomTokenGenerator's own docblock for why
 * a real cryptographically-random value (not `Random::getUniqueHash()`) is the real security
 * boundary for these anonymous, token-only approve/reject links. 32 bytes -> 64 hex chars matches
 * the ordo_order_approval.token column width exactly.
 */
class ApprovalTokenGenerator
{
    public function __construct(private readonly RandomTokenGenerator $randomTokenGenerator)
    {
    }

    public function generate(): string
    {
        return $this->randomTokenGenerator->generate();
    }
}
