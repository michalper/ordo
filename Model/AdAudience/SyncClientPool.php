<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdAudience;

use Ordo\Automation\Api\AdAudience\SyncClientInterface;

/**
 * Platform => client registry, wired via di.xml — same shape as Campaign\ActionPool. Adding a
 * new ad platform never means touching Cron\SyncAdAudiences, just a new class implementing
 * SyncClientInterface and one line in di.xml.
 */
class SyncClientPool
{
    /**
     * @param SyncClientInterface[] $clients platform => instance, wired via di.xml
     */
    public function __construct(
        private readonly array $clients = []
    ) {
    }

    public function get(string $platform): ?SyncClientInterface
    {
        return $this->clients[$platform] ?? null;
    }
}
