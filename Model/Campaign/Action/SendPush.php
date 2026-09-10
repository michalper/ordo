<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"title": "...", "body": "...", "url": "https://..."} - "url" is optional, opened by
 * push-sw.js's notificationclick handler when the browser notification is clicked. Context must
 * include "customer_id".
 *
 * Unlike send_sms/send_whatsapp (one phone number per customer), a customer can have any number
 * of registered browser/device subscriptions (Model\Push\PushSubscriptionManager::getForCustomer())
 * - this sends to every one of them, since there is no single "the" device to pick, and a failed
 * or dead subscription on one device must never stop delivery to the others.
 *
 * Checks ConsentManager::hasConsent() before sending, same as send_email/send_sms/send_whatsapp.
 */
class SendPush implements ActionInterface
{
    private const string CHANNEL = 'push';

    public function __construct(
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PushSender $pushSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly SendRetrier $sendRetrier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            $this->logger->error('Ordo_Automation: send_push action is missing customer_id in context.');
            return;
        }

        if (!$this->config->isPushEnabled()) {
            $this->logger->debug('Ordo_Automation: send_push action skipped, push sending is disabled in config.');
            return;
        }

        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            $this->logger->error('Ordo_Automation: send_push action is missing "title" in params.');
            return;
        }

        if (!$this->consentManager->hasConsent($customerId, ConsentChannel::Push)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_push action skipped for customer #%d, push consent withdrawn.',
                $customerId
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, '');
            return;
        }

        $subscriptions = $this->pushSubscriptionManager->getForCustomer($customerId);
        if ($subscriptions === []) {
            $this->logger->debug(sprintf(
                'Ordo_Automation: send_push action skipped for customer #%d, no registered push subscriptions.',
                $customerId
            ));
            return;
        }

        $payload = (string) json_encode([
            'title' => $title,
            'body' => (string) ($params['body'] ?? ''),
            'url' => (string) ($params['url'] ?? ''),
        ]);

        foreach ($subscriptions as $subscription) {
            // ordo_message_log.to_address is varchar(255) (sized for phone numbers/emails); some
            // push services' endpoint URLs run longer, so this truncates purely for logging - the
            // real, full endpoint used to send always comes straight from the subscription row.
            $endpoint = substr((string) $subscription->getEndpoint(), 0, 255);
            try {
                // A dead/gone subscription (SubscriptionGoneException) is permanently invalid -
                // excluded from SendRetrier's retry loop, same reasoning as SendSms's
                // OptedOutException exclusion.
                $this->sendRetrier->attempt(
                    function () use ($subscription, $payload): void {
                        $this->pushSender->send($subscription, $payload);
                    },
                    static fn (Throwable $e): bool => !$e instanceof SubscriptionGoneException
                );
                $this->messageLogWriter->recordSent(self::CHANNEL, $customerId, $endpoint, null);
            } catch (SubscriptionGoneException) {
                $this->pushSubscriptionManager->delete($subscription);
                $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $endpoint);
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: campaign send_push action failed for customer #%d: %s',
                    $customerId,
                    $e->getMessage()
                ));
                $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $endpoint);
            }
        }
    }
}
