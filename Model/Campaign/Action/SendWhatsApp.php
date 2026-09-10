<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapManager;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
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
 * Checks ConsentManager::hasConsent() before sending, same as send_email/send_sms. Also checks
 * FrequencyCapManager::hasCapacity() right after (opt-in, cross-channel).
 */
class SendWhatsApp implements ActionInterface
{
    private const string CHANNEL = 'whatsapp';

    /**
     * Same E.164 sanity check send_sms uses — see that class's own constant for the reasoning.
     */
    private const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly WhatsAppSender $whatsAppSender,
        private readonly Config $config,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ConsentManager $consentManager,
        private readonly FrequencyCapManager $frequencyCapManager,
        private readonly SendRetrier $sendRetrier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            $this->logger->error('Ordo_Automation: send_whatsapp action is missing customer_id in context.');
            return;
        }

        if (!$this->config->isWhatsAppEnabled()) {
            $this->logger->debug(
                'Ordo_Automation: send_whatsapp action skipped, WhatsApp sending is disabled in config.'
            );
            return;
        }

        $templateId = (int) ($params['template_id'] ?? 0);
        if ($templateId <= 0) {
            $this->logger->error('Ordo_Automation: send_whatsapp action is missing "template_id" in params.');
            return;
        }

        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $templateId);
        if (!$template->getEntityId() || !$template->isApproved()) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_whatsapp action references template #%d which is not an approved'
                . ' WhatsApp template.',
                $templateId
            ));
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable $e) {
            return;
        }

        $phoneAttribute = $customer->getCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE);
        $phone = $phoneAttribute !== null ? trim((string) $phoneAttribute->getValue()) : '';
        if ($phone === '') {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_whatsapp action has no ordo_sms_phone set for customer #%d.',
                $customerId
            ));
            return;
        }

        if (!$this->consentManager->hasConsent($customerId, ConsentChannel::WhatsApp)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_whatsapp action skipped for customer #%d, WhatsApp consent withdrawn.',
                $customerId
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, $phone);
            return;
        }

        if (!$this->frequencyCapManager->hasCapacity($customerId)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: send_whatsapp action skipped for customer #%d, frequency cap reached.',
                $customerId
            ));
            $this->messageLogWriter->recordSuppressed(self::CHANNEL, $customerId, $phone);
            return;
        }

        if (!preg_match(self::E164_PATTERN, $phone)) {
            // Never log the raw phone number here - this module otherwise deliberately hashes/
            // avoids logging PII (see Model/AdAudience/PiiHasher.php), and the customer id alone
            // is enough to look the record up if needed.
            $this->logger->error(sprintf(
                'Ordo_Automation: send_whatsapp action skipped for customer #%d, ordo_sms_phone is not a'
                . ' valid E.164 number.',
                $customerId
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone);
            return;
        }

        $paramsList = $this->parseParams((string) ($params['params'] ?? ''));

        try {
            $providerMessageId = $this->sendRetrier->attempt(fn () => $this->whatsAppSender->send(
                $phone,
                $template->getMetaTemplateName(),
                $template->getLanguage(),
                $paramsList
            ));
            $this->messageLogWriter->recordSent(self::CHANNEL, $customerId, $phone, $providerMessageId);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_whatsapp action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone);
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
}
