<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\MessageIdGenerator;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;

/**
 * Params: {"template": "ordo_campaign_generic", "message": "optional static text"}.
 * Context must include "customer_id" (email resolved via CustomerRepositoryInterface).
 * Every scalar value in $context becomes a template variable, so "send_email" after a
 * "generate_coupon" action on the same campaign can render {{var coupon_code}} for free.
 *
 * Checks ConsentManager::hasConsent() before sending anything — an explicit email opt-out
 * silently skips this action (not an error; skipping is the intended behavior). Also checks
 * FrequencyCapGate::allows() (opt-in, cross-channel) right after — a customer over the
 * configured contact-volume cap is skipped and recorded as suppressed, not sent.
 *
 * Writes to the same channel-generic ordo_message_log SendSms already writes to (see that
 * table's own db_schema.xml comment) — a per-send Message-ID header is queued via
 * PendingMessageIdHolder just before getTransport() (Plugin\Email\EmailMessageMessageIdPlugin
 * actually sets it on the message TransportBuilder builds internally), then stored as
 * provider_message_id, so Controller\Email\StatusCallback's SendGrid Event Webhook can correlate
 * a later delivery-status event back to this exact send, the same role Twilio's message Sid
 * plays for send_sms. MessageLogWriter itself is namespaced under Model\Sms only because it was
 * written first — it is already channel-generic, hence reused here as-is rather than duplicated
 * or relocated.
 */
class SendEmail implements ActionInterface
{
    private const string CHANNEL = 'email';
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly MessageIdGenerator $messageIdGenerator,
        private readonly PendingMessageIdHolder $pendingMessageIdHolder,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly SendRetrier $sendRetrier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $templateIdentifier = (string) ($params['template'] ?? '');
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;

        if ($customerId <= 0 || $templateIdentifier === '') {
            $this->logger->error(
                'Ordo_Automation: send_email action is missing customer_id in context or "template" in params.'
            );
            return;
        }

        if (!$this->consentManager->hasConsent($customerId, ConsentChannel::Email)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_email action skipped for customer #%d, email consent withdrawn.',
                $customerId
            ));
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        if (!$this->quietHoursGate->allows($customerId, $campaignId ?? 0, $actionId, $context)) {
            return;
        }

        if (!$this->frequencyCapGate->allows($customerId, self::CHANNEL, '', 'send_email', $campaignId, $variant)) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            return;
        }

        $store = $this->storeManager->getStore();
        $templateVars = array_merge(
            array_filter($context, is_scalar(...)),
            [
                'customer_name' => $customer->getFirstname(),
                'message' => (string) ($params['message'] ?? ''),
                'store' => $store,
            ]
        );

        $this->inlineTranslation->suspend();

        $messageId = $this->messageIdGenerator->generate();
        $this->pendingMessageIdHolder->set($messageId);

        try {
            // Only the actual network send is retried, not building the transport - a transient
            // SMTP/relay hiccup is exactly the kind of failure that previously dropped this
            // message permanently on its first attempt (see SendRetrier's own docblock).
            $this->sendRetrier->attempt(function () use ($templateIdentifier, $store, $templateVars, $customer) {
                $transport = $this->transportBuilder
                    ->setTemplateIdentifier($templateIdentifier)
                    ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
                    ->setTemplateVars($templateVars)
                    ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
                    ->addTo($customer->getEmail(), $customer->getFirstname())
                    ->getTransport();

                $transport->sendMessage();
            });
            $this->messageLogWriter->recordSent(
                self::CHANNEL,
                $customerId,
                $customer->getEmail(),
                '<' . $messageId . '>',
                $campaignId,
                $variant
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_email action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(
                self::CHANNEL,
                $customerId,
                $customer->getEmail(),
                $campaignId,
                $variant
            );
        } finally {
            $this->pendingMessageIdHolder->consume();
            $this->inlineTranslation->resume();
        }
    }
}
