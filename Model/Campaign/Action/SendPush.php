<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;

/**
 * Params: {"title": "...", "body": "...", "url": "https://..."} - "url" is optional, opened by
 * push-sw.js's notificationclick handler when the browser notification is clicked. "body" may
 * include the literal token "{{recommended_products_text}}", substituted with the plain-text
 * rendering Model\Campaign\Action\AddProductRecommendations writes into the context (empty string
 * if that action never ran, or found nothing to recommend) - not a general templating engine,
 * this one token only, same as send_sms's own equivalent. Context must include "customer_id".
 *
 * Unlike send_sms/send_whatsapp (one phone number per customer), a customer can have any number
 * of registered browser/device subscriptions (Model\Push\PushSubscriptionManager::getForCustomer())
 * - this sends to every one of them, since there is no single "the" device to pick, and a failed
 * or dead subscription on one device must never stop delivery to the others.
 *
 * Checks ConsentManager::hasConsent() before sending, same as send_email/send_sms/send_whatsapp.
 * Also checks FrequencyCapGate::allows() right after (opt-in, cross-channel), once per
 * customer before fanning out to their registered subscriptions.
 *
 * Unlike Send{Email,Sms,WhatsApp} (which enqueue a whole-action retry via
 * Model\Campaign\MessageSendRetryQueue when SendRetrier's in-process retries are exhausted), each
 * subscription's send here is retried individually via Model\Push\PushSubscriptionSender +
 * Model\Push\PushSendRetryQueue - a whole-action retry would risk re-sending to subscriptions
 * that already succeeded the first time, since this fans out to every one of a customer's
 * subscriptions per execute() call.
 */
class SendPush implements ActionInterface
{
    private const string CHANNEL = 'push';

    public function __construct(
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PushSubscriptionSender $pushSubscriptionSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
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
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, '', $campaignId, $variant);
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        if (!$this->quietHoursGate->allows($customerId, $campaignId ?? 0, $actionId, $context)) {
            return;
        }

        if (!$this->frequencyCapGate->allows($customerId, self::CHANNEL, '', 'send_push', $campaignId, $variant)) {
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

        // Only this one token, deliberately - not a general templating engine. See
        // AddProductRecommendations's own docblock for why this context key exists.
        $body = str_replace(
            '{{recommended_products_text}}',
            (string) ($context['recommended_products_text'] ?? ''),
            (string) ($params['body'] ?? '')
        );

        $payload = (string) json_encode([
            'title' => $title,
            'body' => $body,
            'url' => (string) ($params['url'] ?? ''),
        ]);

        foreach ($subscriptions as $subscription) {
            $this->pushSubscriptionSender->send($subscription, $payload, $customerId, $campaignId, $variant);
        }
    }
}
