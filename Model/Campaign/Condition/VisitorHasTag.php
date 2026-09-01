<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\VisitorTagManager;

/**
 * HasTag's counterpart for anonymous visitors. Params: {"tag": "vip"} — same field name as
 * HasTag, reused as the same dedicated form field (see ordo_campaign_form.xml switcherConfig).
 * Context must include "visitor_id" (present on the visitor_tag_added trigger; absent on
 * customer-only triggers like order_placed, which is why this and "tag" are separate condition
 * types rather than one that checks both).
 */
class VisitorHasTag implements ConditionInterface
{
    public function __construct(
        private readonly VisitorTagManager $visitorTagManager
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $visitorId = (string) ($context['visitor_id'] ?? '');
        $tag = (string) ($params['tag'] ?? '');

        if ($visitorId === '' || $tag === '') {
            return false;
        }

        return $this->visitorTagManager->hasTag($visitorId, $tag);
    }
}
