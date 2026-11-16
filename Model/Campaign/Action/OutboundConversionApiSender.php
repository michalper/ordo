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
 * SendWebhook joined later (its own custom X-Ordo-Signature header was the blocker - see
 * $headers below) once that gap was closed.
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
     * @param string[] $redactQueryParams Forwarded to JsonApiClient::postJson() - see its own
     *   docblock. Needed here because $url itself (not just $payload) can carry a live secret,
     *   e.g. SendGa4Event's api_secret/SendMetaConversionEvent's access_token.
     * @param array<string, string> $headers Forwarded to JsonApiClient::postJson() - e.g.
     *   SendWebhook's own HMAC X-Ordo-Signature header, which this API's auth scheme (a query
     *   param secret, same as $redactQueryParams above) never needs.
     */
    public function send(
        string $actionType,
        string $rateLimitChannel,
        float $maxRequestsPerSecond,
        string $url,
        array $payload,
        string $serviceName,
        array &$context,
        array $params,
        array $redactQueryParams = [],
        array $headers = []
    ): void {
        try {
            $this->rateLimiter->throttle($rateLimitChannel, $maxRequestsPerSecond);
            $this->sendRetrier->attempt(
                fn () => $this->jsonApiClient->postJson($url, $payload, $headers, $serviceName, $redactQueryParams)
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
