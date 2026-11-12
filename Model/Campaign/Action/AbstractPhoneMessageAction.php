<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\ConsentTopicOptions;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared skeleton for every phone-number-addressed campaign action (Model\Campaign\Action\SendSms,
 * SendRcs) - resolve the customer, check the channel's own enabled flag (store-scoped), resolve
 * the phone (the shared ordo_sms_phone customer attribute), check consent, quiet hours, the
 * frequency cap, validate E.164, substitute the one "{{recommended_products_text}}" token - all
 * of it is genuinely identical across channels, parameterized only by the small set of abstract
 * methods below. Extracted after Model\Campaign\Action\SendRcs/SendSms crossed Sonar's new-code
 * duplication gate (see ADR 0049) - the one piece that's deliberately NOT shared is the actual
 * send+record-outcome step (sendMessage()), since that's where SMS's Twilio-STOP-keyword opt-out
 * detection and retry-queue participation genuinely diverge from RCS's simpler "send once, no
 * retry" shape.
 */
abstract class AbstractPhoneMessageAction implements ActionInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        protected readonly Config $config,
        protected readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly QuietHoursGate $quietHoursGate,
        private readonly FrequencyCapGate $frequencyCapGate,
        protected readonly LoggerInterface $logger,
        private readonly CustomerStoreResolver $customerStoreResolver,
        private readonly PhoneNumberResolver $phoneNumberResolver
    ) {
    }

    /**
     * The channel string ordo_message_log/FrequencyCapGate key on ('sms'/'rcs').
     */
    abstract protected function channel(): string;

    /**
     * The campaign action type key ('send_sms'/'send_rcs'), used in log messages and as
     * FrequencyCapGate's action-type argument.
     */
    abstract protected function actionType(): string;

    abstract protected function consentChannel(): ConsentChannel;

    abstract protected function isChannelEnabled(?int $storeId): bool;

    /**
     * The "message" param field's label ("SMS message"/"RCS message") - the one piece of
     * getParamFields() that genuinely differs; see consentTopicField() for the shared part.
     */
    abstract protected function messageFieldLabel(): string;

    /**
     * Sends the already-validated, already-token-substituted message and records its outcome
     * (sent/failed, and whatever channel-specific semantics apply - e.g. SMS's opted-out/retry-
     * queue handling) - the one step that genuinely differs between channels.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    abstract protected function sendMessage(string $phone, string $message, array &$context, array $params): void;

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        if ($customerId <= 0) {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action is missing customer_id in context.',
                $this->actionType()
            ));
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable) {
            return;
        }

        // The customer's own store, not Magento's ambient "current store" - fetched before the
        // config check specifically so this check is store-scoped too.
        if (!$this->isChannelEnabled($this->customerStoreResolver->resolveStoreId($customer))) {
            $this->logger->debug(sprintf(
                'Ordo_Automation: %s action skipped, %s sending is disabled in config.',
                $this->actionType(),
                strtoupper($this->channel())
            ));
            return;
        }

        $phone = $this->phoneNumberResolver->resolve($customer);
        if ($phone === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action has no ordo_sms_phone set for customer #%d.',
                $this->actionType(),
                $customerId
            ));
            return;
        }

        $topic = ConsentTopic::tryFrom((string) ($params['topic'] ?? '')) ?? ConsentTopic::Promotional;
        if (!$this->consentManager->hasTopicConsent($customerId, $this->consentChannel(), $topic)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: %s action skipped for customer #%d, %s consent withdrawn.',
                $this->actionType(),
                $customerId,
                strtoupper($this->channel())
            ));
            $this->messageLogWriter->recordOptedOut($this->channel(), $customerId, $phone, $campaignId, $variant);
            return;
        }

        $actionId = (int) ($context['ordo_action_id'] ?? 0);
        if (!$this->quietHoursGate->allows($customerId, $campaignId ?? 0, $actionId, $context)) {
            return;
        }

        if (!$this->frequencyCapGate->allows(
            $customerId,
            $this->channel(),
            $phone,
            $this->actionType(),
            $campaignId,
            $variant
        )) {
            return;
        }

        if (!$this->phoneNumberResolver->isValidE164($phone)) {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action skipped for customer #%d, ordo_sms_phone "%s" is not a valid'
                . ' E.164 number.',
                $this->actionType(),
                $customerId,
                $phone
            ));
            $this->messageLogWriter->recordFailed($this->channel(), $customerId, $phone, $campaignId, $variant);
            return;
        }

        $message = trim((string) ($params['message'] ?? ''));
        if ($message === '') {
            $this->logger->error(sprintf(
                'Ordo_Automation: %s action is missing "message" in params.',
                $this->actionType()
            ));
            return;
        }

        // Only this one token, deliberately - not a general templating engine. See
        // AddProductRecommendations's own docblock for why this context key exists.
        $message = str_replace(
            '{{recommended_products_text}}',
            (string) ($context['recommended_products_text'] ?? ''),
            $message
        );

        $this->sendMessage($phone, $message, $context, $params);
    }

    /**
     * @return array<int, array{
     *     name: string, label: string, notice?: string, options?: array<int|string, string>, type?: string
     * }>
     */
    public function getParamFields(): array
    {
        return [
            [
                'name' => 'message',
                'label' => $this->messageFieldLabel(),
            ],
            $this->consentTopicField(),
        ];
    }

    /**
     * Byte-for-byte identical across every phone-message channel - the opt-out preference a
     * send is checked against is a module-wide concept (Model\ConsentTopic), not a per-channel
     * one.
     *
     * @return array{name: string, label: string, notice: string, options: array<string, string>}
     */
    protected function consentTopicField(): array
    {
        return [
            'name' => 'topic',
            'label' => (string) __('Consent topic'),
            'options' => ConsentTopicOptions::toMap(),
            'notice' => (string) __(
                'Which opt-out preference this send is checked against, beyond the'
                . ' customer\'s own per-channel consent. Defaults to Promotional if left blank.'
            ),
        ];
    }
}
