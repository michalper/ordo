<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Approval;

/**
 * Generates the order-approval decision token - plain `\Magento\Framework\Math\Random::getUniqueHash()`
 * (md5(uniqid(mt_rand(), true))) is only a 128-bit digest of a non-cryptographic PRNG, not the
 * "32+ byte random value" ApprovalRateLimiter's own doc comment claims is the real security
 * boundary for these anonymous, token-only approve/reject links. 64 hex chars matches the
 * ordo_order_approval.token column width exactly.
 */
class ApprovalTokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
