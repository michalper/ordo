<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Api\Campaign\ActionInterface;

class ActionPool
{
    /**
     * @param ActionInterface[] $actions type => instance, wired via di.xml
     */
    public function __construct(
        private readonly array $actions = []
    ) {
    }

    public function get(string $type): ?ActionInterface
    {
        return $this->actions[$type] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableTypes(): array
    {
        return array_keys($this->actions);
    }

    /**
     * 'split' deliberately has no entry in $this->actions - CampaignDispatcher::runSplit()
     * intercepts type === 'split' before the pool lookup (the same reserved-pseudo-type shape
     * Model\Condition\GroupWalker's 'group' condition type has), so there is no ActionInterface
     * class to read its one dedicated field's metadata from. Both CampaignSaveProcessor and
     * Block\Adminhtml\Campaign\Edit\Flow need that same field descriptor, so it's hand-declared
     * once here (where this pool's one exception naturally belongs) rather than duplicated in
     * each.
     *
     * @return array<int, array{name: string, label: string, type: string, notice: string}>
     */
    public static function splitActionFields(): array
    {
        return [
            [
                'name' => 'variants',
                'label' => (string) __('Variants'),
                'type' => 'variant_list',
                'notice' => (string) __(
                    'Each variant gets a relative weight (they don\'t need to sum to 100)'
                    . ' and its own action chain. A customer is assigned to exactly one'
                    . ' variant, deterministically, the first time they enter this split -'
                    . ' repeat dispatches (and a delayed resume elsewhere in the chain)'
                    . ' always land in the same variant. A variant\'s own actions can\'t'
                    . ' have their own delay yet.'
                ),
            ],
        ];
    }
}
