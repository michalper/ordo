<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Email;

use Ordo\Automation\Model\ConsentChannel;

/**
 * Resolved, signature-verified payload of a UnsubscribeTokenManager::resolve() call - see that
 * class's own docblock for the token shape this comes from.
 */
class UnsubscribeToken
{
    public function __construct(
        public readonly int $customerId,
        public readonly ConsentChannel $channel
    ) {
    }
}
