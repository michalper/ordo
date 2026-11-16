<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Campaign\OptionSourceFlattener;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\Config\Source\WhatsAppTemplateOptions;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"template_id": "3", "params": "value1,value2"}. Context must include "customer_id"
 * (phone resolved via the same ordo_sms_phone customer attribute send_sms already uses - one
 * phone number attribute serving both channels, not a second one, since a customer only has one
 * mobile number regardless of which channel eventually messages it).
 *
 * ALWAYS sends a template message, never free text — the WhatsApp Business Platform only allows
 * a free-form message inside a 24h customer-service window a campaign dispatch has no reliable
 * way to know is open for a given recipient, so template_id (an APPROVED WhatsAppTemplate, see
 * that model's own STATUS_* lifecycle) is the only way this action can address someone. "params"
 * is a comma-separated list of values filled positionally into the template's {{1}}, {{2}}, ...
 * body placeholders — deliberately not JSON-nested, so the campaign flow editor's own plain-text
 * field (Block/Adminhtml/Campaign/Edit/Flow.php::getFieldsConfig()) can drive it directly.
 *
 * Extends AbstractPhoneMessageAction (see ADR 0066) for the customer/phone/consent/quiet-hours/
 * frequency-cap/E.164 skeleton every phone-addressed channel shares - validateContent() is
 * overridden entirely for this channel's template-based content model instead of the base's
 * free-text "message" field, so template validation now runs where that field's own validation
 * used to (after consent/quiet-hours/frequency-cap/E.164, not before phone resolution as this
 * class used to check it) - a deliberate reordering to fit the shared shape, not a behavior
 * change in outcome: either way a bad template config logs an error and sends nothing.
 */
class SendWhatsApp extends AbstractPhoneMessageAction
{
    private const string CHANNEL = 'whatsapp';
    private const string ACTION_TYPE = 'send_whatsapp';

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        private readonly WhatsAppSender $whatsAppSender,
        Config $config,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource,
        MessageLogWriter $messageLogWriter,
        ConsentManager $consentManager,
        QuietHoursGate $quietHoursGate,
        FrequencyCapGate $frequencyCapGate,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        LoggerInterface $logger,
        PhoneNumberResolver $phoneNumberResolver,
        private readonly WhatsAppTemplateOptions $whatsAppTemplateOptions,
        CustomerStoreResolver $customerStoreResolver
    ) {
        parent::__construct(
            $customerRepository,
            $config,
            $messageLogWriter,
            $consentManager,
            $quietHoursGate,
            $frequencyCapGate,
            $logger,
            $customerStoreResolver,
            $phoneNumberResolver
        );
    }

    protected function channel(): string
    {
        return self::CHANNEL;
    }

    protected function actionType(): string
    {
        return self::ACTION_TYPE;
    }

    protected function consentChannel(): ConsentChannel
    {
        return ConsentChannel::WhatsApp;
    }

    protected function isChannelEnabled(?int $storeId): bool
    {
        return $this->config->isWhatsAppEnabled($storeId);
    }

    /**
     * This channel's content field isn't called "message" (see getParamFields()'s own
     * "template_id" field below), but the label text itself is still shared through this hook -
     * same reasoning as every other abstract method here, just reused for a different field name.
     */
    protected function messageFieldLabel(): string
    {
        return (string) __('WhatsApp template');
    }

    protected function validateContent(array $params): bool
    {
        $templateId = (int) ($params['template_id'] ?? 0);
        if ($templateId <= 0) {
            $this->logger->error('Ordo_Automation: send_whatsapp action is missing "template_id" in params.');
            return false;
        }

        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $templateId);
        if (!$template->getEntityId() || !$template->isApproved()) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_whatsapp action references template #%d which is not an approved'
                . ' WhatsApp template.',
                $templateId
            ));
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $params
     */
    protected function sendMessage(string $phone, array &$context, array $params): void
    {
        // Reloaded rather than threaded through from validateContent() - keeps the base class's
        // validateContent() contract a plain bool gate, with no generic "content" type to invent
        // just for this one template-based channel. A second primary-key lookup of the same row
        // is cheap; a per-batch template cache (one load across every customer in a dispatch,
        // not just once per customer) is the real win here and is tracked separately.
        $templateId = (int) $params['template_id'];
        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $templateId);

        $customerId = (int) ($context['customer_id'] ?? 0);
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;
        $variant = isset($context['ordo_split_variant']) ? (string) $context['ordo_split_variant'] : null;
        $triggerOrderId = isset($context['order_id']) ? (int) $context['order_id'] : null;

        $paramsList = $this->parseParams((string) ($params['params'] ?? ''));

        try {
            $providerMessageId = $this->sendRetrier->attempt(fn () => $this->whatsAppSender->send(
                $phone,
                $template->getMetaTemplateName(),
                $template->getLanguage(),
                $paramsList
            ));
            $this->messageLogWriter->recordSent(
                self::CHANNEL,
                $customerId,
                $phone,
                $providerMessageId,
                $campaignId,
                $variant,
                $triggerOrderId
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_whatsapp action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone, $campaignId, $variant);

            // Rethrows on a retry attempt instead of enqueuing again - see
            // MessageSendRetryQueue::enqueueOrRethrow()/RETRY_CONTEXT_FLAG.
            $this->messageSendRetryQueue->enqueueOrRethrow(self::ACTION_TYPE, $context, $params, $e);
        }
    }

    /**
     * @return string[]
     */
    private function parseParams(string $paramsCsv): array
    {
        $paramsCsv = trim($paramsCsv);
        if ($paramsCsv === '') {
            return [];
        }

        return array_map('trim', explode(',', $paramsCsv));
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'template_id',
                'label' => $this->messageFieldLabel(),
                'options' => OptionSourceFlattener::toMap($this->whatsAppTemplateOptions),
                'notice' => (string) __(
                    'Only APPROVED templates appear here — the WhatsApp Business Platform never'
                    . ' allows a free-form message from a campaign send.'
                ),
            ],
            [
                'name' => 'params',
                'label' => (string) __('Template parameters (comma-separated, optional)'),
                'notice' => (string) __(
                    'Fills the template\'s {{1}}, {{2}}, ... placeholders in order, e.g. for a'
                    . ' template body of "Hi {{1}}, your order {{2}} shipped": John,ORD-1234'
                ),
            ],
            $this->consentTopicField(),
        ];
    }
}
