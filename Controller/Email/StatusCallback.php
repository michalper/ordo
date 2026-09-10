<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Email;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\SendGridSignatureValidator;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Public, unauthenticated endpoint registered once, by URL, in the SendGrid account's own Event
 * Webhook settings (not per-message like Twilio's statusCallback param — SendGrid pushes every
 * event for the whole account here) — same trust model as Controller\Sms\StatusCallback:
 * X-Twilio-Email-Event-Webhook-Signature (SendGrid's Event Webhook product is, confusingly,
 * "Twilio SendGrid" branded, hence the header name) is the mandatory trust boundary, checked
 * before anything else.
 *
 * Handles "delivered"/"bounce"/"dropped" (terminal delivery outcomes) and, as of a real-bug fix,
 * "unsubscribe"/"group_unsubscribe"/"spamreport" too — those three are opt-out signals a
 * recipient sent through their own mailbox/inbox provider rather than through this module's own
 * UI, and previously went straight into the "unhandled, silently skipped" bucket below: a
 * one-click unsubscribe or spam complaint never reached ConsentManager, so send_email kept
 * mailing someone who had, in every real sense, opted out (deliverability/compliance risk).
 * SendGrid's remaining event types (open, click, processed, deferred, resubscribe) still aren't
 * terminal delivery outcomes or opt-out signals, so they're still silently skipped, not an error.
 */
class StatusCallback extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const string SIGNATURE_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';
    private const string TIMESTAMP_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';

    /**
     * @var array<string, string> SendGrid event type => MessageLog::STATUS_* constant.
     */
    private const array EVENT_TO_STATUS = [
        'delivered' => MessageLog::STATUS_DELIVERED,
        'bounce' => MessageLog::STATUS_UNDELIVERED,
        'dropped' => MessageLog::STATUS_FAILED,
        'unsubscribe' => MessageLog::STATUS_OPTED_OUT,
        'group_unsubscribe' => MessageLog::STATUS_OPTED_OUT,
        'spamreport' => MessageLog::STATUS_OPTED_OUT,
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly SendGridSignatureValidator $signatureValidator,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly MessageLogResource $messageLogResource,
        private readonly ConsentManager $consentManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $signature = (string) $this->getRequest()->getHeader(self::SIGNATURE_HEADER);
        $timestamp = (string) $this->getRequest()->getHeader(self::TIMESTAMP_HEADER);
        $rawBody = (string) $this->getRequest()->getContent();

        $isValid = $signature !== '' && $timestamp !== '' && $this->signatureValidator->isValid(
            $this->config->getSendGridWebhookVerificationKey(),
            $timestamp,
            $rawBody,
            $signature
        );
        if (!$isValid) {
            $this->logger->error(
                'Ordo_Automation: rejected a SendGrid event webhook call with an invalid signature.'
            );

            return $result->setHttpResponseCode(403)->setData(['ok' => false]);
        }

        $events = json_decode($rawBody, true);
        if (!is_array($events)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        foreach ($events as $event) {
            if (is_array($event)) {
                $this->processEvent($event);
            }
        }

        return $result->setData(['ok' => true]);
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private function processEvent(array $event): void
    {
        $eventType = (string) ($event['event'] ?? '');
        $status = self::EVENT_TO_STATUS[$eventType] ?? null;
        $messageId = (string) ($event['smtp-id'] ?? '');
        if ($status === null || $messageId === '') {
            return;
        }

        $collection = $this->messageLogCollectionFactory->create();
        $collection->addFieldToFilter('provider_message_id', $messageId);
        /** @var MessageLog $log */
        $log = $collection->getFirstItem();

        if (!$log->getId()) {
            // SendGrid also retries on a non-2xx response for the whole batch - an unrecognized
            // smtp-id just means this one event is skipped, same reasoning as
            // Controller\Sms\StatusCallback's own unknown-MessageSid branch.
            $this->logger->info(sprintf(
                'Ordo_Automation: SendGrid event webhook for unknown smtp-id "%s" (event=%s).',
                $messageId,
                $eventType
            ));

            return;
        }

        $log->setStatus($status);
        $reason = $event['reason'] ?? $event['type'] ?? null;
        $log->setErrorCode($reason !== null ? (string) $reason : null);
        $this->messageLogResource->save($log);

        // Record the opt-out against the customer's actual consent state, not just this one
        // message's log row - otherwise the next send_email campaign action would mail them
        // again regardless, since SendEmail checks ConsentManager, not ordo_message_log.
        if ($status === MessageLog::STATUS_OPTED_OUT && $log->getCustomerId() !== null) {
            $this->consentManager->setConsent(
                $log->getCustomerId(),
                ConsentChannel::Email,
                false,
                'sendgrid_' . $eventType
            );
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
