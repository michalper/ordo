<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared "throttle, POST with retry, log+enqueue-for-retry on failure" tail - SendGa4Event and
 * SendMetaConversionEvent had this byte-for-byte identical (only the channel name/rate/url/
 * payload/service name differ), caught by SonarCloud's new-code duplication gate during review.
 * Not reused by the older Model\Campaign\Action\SendWebhook, which predates this class and isn't
 * part of what triggered that gate - a wider project-wide dedup pass is a separate concern.
 */
class OutboundConversionApiSender
{
    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    public function send(
        string $actionType,
        string $rateLimitChannel,
        float $maxRequestsPerSecond,
        string $url,
        array $payload,
        string $serviceName,
        array &$context,
        array $params
    ): void {
        try {
            $this->rateLimiter->throttle($rateLimitChannel, $maxRequestsPerSecond);
            $this->sendRetrier->attempt(
                fn () => $this->jsonApiClient->postJson($url, $payload, [], $serviceName)
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action failed to reach %s: %s',
                $actionType,
                $serviceName,
                $e->getMessage()
            ));
            $this->messageSendRetryQueue->enqueueOrRethrow($actionType, $context, $params, $e);
        }
    }
}
