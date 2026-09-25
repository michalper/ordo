<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Math\Random;

/**
 * Generates an 8-character uppercase alphanumeric referral code - short enough to type/read
 * aloud (the whole point of a referral code, unlike CouponGenerator's longer, prefixed codes),
 * with a small collision-retry loop since it's shared across the whole customer base rather than
 * scoped to one rule the way a coupon code is.
 */
class ReferralCodeGenerator
{
    private const int CODE_LENGTH = 8;
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Random $random
    ) {
    }

    /**
     * @param callable(string): bool $isTaken Returns true if the candidate code is already in use.
     */
    public function generateUnique(callable $isTaken): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = strtoupper($this->random->getRandomString(self::CODE_LENGTH));
            if (!$isTaken($code)) {
                return $code;
            }
        }

        // Astronomically unlikely to ever reach this (36^8 possible codes) - one final attempt
        // with a longer code rather than silently returning a colliding one.
        return strtoupper($this->random->getRandomString(self::CODE_LENGTH + 4));
    }
}
