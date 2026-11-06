<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\ConsentTopicOptions;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Campaign\OptionSourceFlattener;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\Campaign\SendTimeOptimizationGate;
use Ordo\Automation\Model\Config\Source\EmailTemplateOptions;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Email\MessageIdGenerator;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;

/**
 * Params: {"template": "ordo_campaign_generic", "message": "optional static text", "topic":
 * "promotional"|"account_updates" (optional, defaults to "promotional")}.
 * Context must include "customer_id" (email resolved via CustomerRepositoryInterface).
 * Every scalar value in $context becomes a template variable, so "send_email" after a
 * "generate_coupon" action on the same campaign can render {{var coupon_code}} for free.
 *
 * Checks ConsentManager::hasTopicConsent() before sending anything — an explicit email opt-out,
 * at either the channel level or this send's own topic (see ConsentTopic), silently skips this
 * action (not an error; skipping is the intended behavior). Also checks FrequencyCapGate::
 * allows() (opt-in, cross-channel) right after — a customer over the configured contact-volume
 * cap is skipped and recorded as suppressed, not sent.
 *
 * "use_optimal_send_time" (bool, default false) in `params` opts this specific action into
 * SendTimeOptimizationGate — deferring to the customer's own historically-best email open/click
 * hour instead of sending right now. Checked before QuietHoursGate (see that gate's own docblock
 * for why no extra coordination is needed between the two).
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
    private const string ACTION_TYPE = 'send_email';
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly ConsentManager $consentManager,
        private readonly SendTimeOptimizationGate $sendTimeOptimizationGate,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        private readonly MessageIdGenerator $messageIdGenerator,
        private readonly PendingMessageIdHolder $pendingMessageIdHolder,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        private readonly LoggerInterface $logger,
        private readonly CustomerStoreResolver $customerStoreResolver,
        private readonly EmailTemplateOptions $emailTemplateOptions
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $templateIdentifier = (string) ($params['template'] ?? '');
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        $triggerOrderId = isset($context['order_id']) ? (int) $context['order_id'] : null;

        if ($customerId <= 0 || $templateIdentifier === '') {
            $this->logger->error(
                'Ordo_Automation: send_email action is missing customer_id in context or "template" in params.'
            );
            return;
        }

        $topic = ConsentTopic::tryFrom((string) ($params['topic'] ?? '')) ?? ConsentTopic::Promotional;
        if (!$this->consentManager->hasTopicConsent($customerId, ConsentChannel::Email, $topic)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_email action skipped for customer #%d, email consent withdrawn.',
                $customerId
            ));
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        $useOptimalSendTime = (bool) ($params['use_optimal_send_time'] ?? false);
        if (!$this->sendTimeOptimizationGate->allows(
            $customerId,
            $campaignId ?? 0,
            $actionId,
            $context,
            $useOptimalSendTime
        )) {
            return;
        }

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

        // The customer's own store, not Magento's ambient "current store" - in a cron/CLI
        // dispatch context (which this always is, see ADR 0001) that ambient store is whatever
        // happens to be first/default, wrong for any customer not actually on that store.
        // getStore() falls back to the current store if the resolved id is null, same as before.
        $store = $this->storeManager->getStore($this->customerStoreResolver->resolveStoreId($customer));
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
                $variant,
                $triggerOrderId
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

            // Rethrows on a retry attempt instead of enqueuing again - see
            // MessageSendRetryQueue::enqueueOrRethrow()/RETRY_CONTEXT_FLAG.
            $this->messageSendRetryQueue->enqueueOrRethrow(self::ACTION_TYPE, $context, $params, $e);
        } finally {
            $this->pendingMessageIdHolder->consume();
            $this->inlineTranslation->resume();
        }
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'template',
                'label' => (string) __('Email template'),
                'options' => OptionSourceFlattener::toMap($this->emailTemplateOptions),
            ],
            ['name' => 'message', 'label' => (string) __('Message')],
            [
                'name' => 'use_optimal_send_time',
                'label' => (string) __('Send at customer\'s optimal time'),
                'options' => [
                    '' => (string) __('No - send immediately'),
                    'true' => (string) __(
                        'Yes - defer to this customer\'s own historically-best open/click hour'
                        . ' (Model\Campaign\SendTimeOptimizer), when enough history exists'
                    ),
                ],
            ],
            [
                'name' => 'topic',
                'label' => (string) __('Consent topic'),
                'options' => ConsentTopicOptions::toMap(),
                'notice' => (string) __(
                    'Which opt-out preference this send is checked against, beyond the'
                    . ' customer\'s own per-channel consent. Defaults to Promotional if left blank.'
                ),
            ],
        ];
    }
}
