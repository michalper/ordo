<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\PushSubscription;

/**
 * Stands in for a real push service - never makes a real HTTP call. Extends the real PushSender
 * (real Curl/Config/VapidTokenBuilder/WebPushCrypto/PushEndpointValidator/OutboundRateLimiter
 * dependencies from DI, all harmless to construct) and overrides only send() itself, the one
 * method that would otherwise make the real outbound HTTP request.
 */
class FakePushSender extends PushSender
{
    public bool $shouldSucceed = true;

    public int $callCount = 0;

    public function send(PushSubscription $subscription, string $payloadJson): void
    {
        $this->callCount++;

        if (!$this->shouldSucceed) {
            throw new \RuntimeException('Simulated push service failure for integration test');
        }
    }
}
