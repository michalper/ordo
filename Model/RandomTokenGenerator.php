<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The one cryptographically-random token primitive this module needs wherever a value must be
 * unguessable, not just unique - `\Magento\Framework\Math\Random::getUniqueHash()`
 * (md5(uniqid(mt_rand(), true))) is only a 128-bit digest of a non-cryptographic PRNG, not a real
 * security boundary. Found hand-duplicated in Model\AiAgent\ApiKeyGenerator and
 * Model\Approval\ApprovalTokenGenerator during a full-repo /simplify sweep - the latter's own
 * docblock already argued at length for random_bytes() over getUniqueHash(), an argument that
 * applies verbatim to the former.
 */
class RandomTokenGenerator
{
    /**
     * @param positive-int $bytes
     */
    public function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
