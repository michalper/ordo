<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;

/**
 * Feed code => generator registry, wired via di.xml — same shape as
 * Model\AdAudience\SyncClientPool. Adding a third feed format never means touching
 * Cron\RefreshProductFeed or Controller\Adminhtml\ProductFeed\RefreshNow, just a new class
 * implementing FeedGeneratorInterface and one line in di.xml.
 */
class FeedGeneratorPool
{
    /**
     * @param FeedGeneratorInterface[] $generators feed code => instance, wired via di.xml
     */
    public function __construct(
        private readonly array $generators = []
    ) {
    }

    /**
     * @return FeedGeneratorInterface[]
     */
    public function getAll(): array
    {
        return $this->generators;
    }
}
