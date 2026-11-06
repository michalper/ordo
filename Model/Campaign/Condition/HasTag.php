<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\OptionSourceFlattener;
use Ordo\Automation\Model\Config\Source\TagMatchMode;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Params: {"tags": ["vip", "loyal"], "match_mode": "any"|"all"} (match_mode defaults to "any"
 * when absent/unrecognized). Context must include "customer_id".
 *
 * Implements BulkResolvableConditionInterface (mirrors ClvAtLeast) so SegmentMemberResolver
 * resolves this one through its default BulkResolvableConditionInterface arm rather than a
 * dedicated switch case - this type used to have its own hardcoded `case 'tag':` arm there;
 * nothing else depended on that, so it was deleted in favor of the general mechanism.
 */
class HasTag implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly CustomerTagManager $customerTagManager,
        private readonly TagMatchMode $tagMatchModeOptions
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        [$tags, $matchAll] = $this->readParams($params);

        if ($customerId <= 0 || $tags === []) {
            return false;
        }

        return $matchAll
            ? $this->customerTagManager->hasAllTags($customerId, $tags)
            : $this->customerTagManager->hasAnyTag($customerId, $tags);
    }

    public function resolveCustomerIds(array $params): array
    {
        [$tags, $matchAll] = $this->readParams($params);

        if ($tags === []) {
            return [];
        }

        return $matchAll
            ? $this->customerTagManager->getCustomerIdsWithAllTags($tags)
            : $this->customerTagManager->getCustomerIdsWithAnyTag($tags);
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

    /**
     * Shared verbatim by VisitorHasTag - same two fields, same "tags" field name, see that
     * class's own getParamFields().
     */
    public function getParamFields(): array
    {
        return [
            ['name' => 'tags', 'label' => (string) __('Tags'), 'type' => 'tag_multiselect'],
            [
                'name' => 'match_mode',
                'label' => (string) __('Match'),
                'options' => OptionSourceFlattener::toMap($this->tagMatchModeOptions),
            ],
        ];
    }
}
