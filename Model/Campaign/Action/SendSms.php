<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\OptedOutException;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"message": "SMS text"}. Context must include "customer_id" (phone resolved via the
 * dedicated ordo_sms_phone customer attribute — not the core address telephone, see
 * AddCustomerSmsPhoneAttribute). Mirrors SendEmail's shape: read customer_id, resolve the
 * customer, resolve the delivery target, then hand off to a provider abstraction
 * (SmsSenderInterface, Twilio-backed by default via di.xml preference) inside a try/catch that
 * logs and swallows — a failed SMS never blocks the rest of the campaign's actions.
 */
class SendSms implements ActionInterface
{
    private const CHANNEL = 'sms';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SmsSenderInterface $smsSender,
        private readonly Config $config,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            $this->logger->error('Ordo_Automation: send_sms action is missing customer_id in context.');
            return;
        }

        if (!$this->config->isSmsEnabled()) {
            $this->logger->debug('Ordo_Automation: send_sms action skipped, SMS sending is disabled in config.');
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
                'Ordo_Automation: send_sms action has no ordo_sms_phone set for customer #%d.',
                $customerId
            ));
            return;
        }

        $message = trim((string) ($params['message'] ?? ''));
        if ($message === '') {
            $this->logger->error('Ordo_Automation: send_sms action is missing "message" in params.');
            return;
        }

        try {
            $providerMessageId = $this->smsSender->send($phone, $message);
            $this->messageLogWriter->recordSent(self::CHANNEL, $customerId, $phone, $providerMessageId);
        } catch (OptedOutException $e) {
            // Expected, routine outcome (Twilio's own STOP/opt-out handling) — not a delivery
            // failure, so this logs at a distinctly lower severity than the generic catch below
            // and records it as opted_out, not failed, in ordo_message_log.
            $this->logger->info(sprintf(
                'Ordo_Automation: send_sms action skipped for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordOptedOut(self::CHANNEL, $customerId, $phone);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_sms action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
            $this->messageLogWriter->recordFailed(self::CHANNEL, $customerId, $phone);
        }
    }
}
