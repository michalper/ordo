<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

use Ordo\Automation\Model\ContentBlock\Producer\ProducerInterface;

class ProducerPool
{
    /**
     * @param ProducerInterface[] $producers type => instance, wired via di.xml
     */
    public function __construct(
        private readonly array $producers = []
    ) {
    }

    public function get(string $type): ?ProducerInterface
    {
        return $this->producers[$type] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableTypes(): array
    {
        return array_keys($this->producers);
    }
}
