<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Config\Source\TagMatchMode;
use Ordo\Automation\Model\VisitorTagManager;

/**
 * HasTag's counterpart for anonymous visitors. Params: {"tags": ["vip", "loyal"],
 * "match_mode": "any"|"all"} — same field name ("tags") as HasTag, reused as the same dedicated
 * form field (see ordo_campaign_form.xml switcherConfig). Context must include "visitor_id"
 * (present on the visitor_tag_added trigger; absent on customer-only triggers like order_placed,
 * which is why this and HasTag are separate condition types rather than one that checks both).
 *
 * Not BulkResolvableConditionInterface - an anonymous visitor has no customer_id, so this can
 * never resolve at the segment/set level (see SegmentMemberResolver's own
 * `case 'visitor_tag': return [];` arm, unchanged by this class).
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
        [$tags, $matchAll] = $this->readParams($params);

        if ($visitorId === '' || $tags === []) {
            return false;
        }

        return $matchAll
            ? $this->visitorTagManager->hasAllTags($visitorId, $tags)
            : $this->visitorTagManager->hasAnyTag($visitorId, $tags);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string[], 1: bool}
     */
    private function readParams(array $params): array
    {
        $tags = $params['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        $tags = array_values(array_unique(array_filter(
            array_map(static fn ($tag) => trim((string) $tag), $tags),
            static fn (string $tag) => $tag !== ''
        )));

        $matchAll = ($params['match_mode'] ?? TagMatchMode::ANY) === TagMatchMode::ALL;

        return [$tags, $matchAll];
    }
}
