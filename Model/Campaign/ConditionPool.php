<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Api\Campaign\ConditionInterface;

class ConditionPool
{
    /**
     * @param ConditionInterface[] $conditions type => instance, wired via di.xml
     */
    public function __construct(
        private readonly array $conditions = []
    ) {
    }

    public function get(string $type): ?ConditionInterface
    {
        return $this->conditions[$type] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableTypes(): array
    {
        return array_keys($this->conditions);
    }
}
