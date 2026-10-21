<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Model\AdAudience\SyncClientPool;
use PHPUnit\Framework\TestCase;

class SyncClientPoolTest extends TestCase
{
    public function testGetReturnsRegisteredClient(): void
    {
        $client = $this->createStub(SyncClientInterface::class);
        $pool = new SyncClientPool(['google_ads' => $client]);

        self::assertSame($client, $pool->get('google_ads'));
    }

    public function testGetReturnsNullForUnknownPlatform(): void
    {
        self::assertNull((new SyncClientPool())->get('missing'));
    }

}
