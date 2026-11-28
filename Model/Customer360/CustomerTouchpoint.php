<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Customer360;

/**
 * One row of CustomerTouchpointReader::getTimeline()'s chronological history - see that class's
 * own docblock for where each type comes from.
 */
class CustomerTouchpoint
{
    public function __construct(
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly ?string $channel,
        public readonly ?string $campaignName,
        public readonly ?int $orderId
    ) {
    }
}
