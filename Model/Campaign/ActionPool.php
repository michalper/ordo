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
}
