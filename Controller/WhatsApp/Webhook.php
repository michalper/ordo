<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\WhatsApp;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLog\StatusDowngradeGuard;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use Ordo\Automation\Model\WhatsApp\WhatsAppSignatureValidator;
use Ordo\Automation\Model\WhatsAppTemplate;
use Psr\Log\LoggerInterface;

/**
 * Public, unauthenticated endpoint - registered once, in the Meta App Dashboard's own Webhooks
 * settings, not per-message (same "one URL for the whole account" shape as Controller\Email\
 * StatusCallback's SendGrid Event Webhook). Handles BOTH halves of Meta's real webhook contract
 * in one controller, branching on HTTP method, because Meta itself only ever registers a single
 * callback URL for both:
 *  - GET is Meta's one-time subscription handshake (hub.mode/hub.verify_token/hub.challenge) -
 *    echoes hub.challenge back as plain text only if hub.verify_token matches this store's own
 *    configured token, otherwise 403.
 *  - POST is a real event delivery, signature-verified via X-Hub-Signature-256 before anything
 *    else (same trust-boundary-first pattern as every other webhook controller in this module) -
 *    carries either message delivery-status updates ("statuses", correlated to ordo_message_log
 *    by provider_message_id, same role Twilio's Sid/SendGrid's smtp-id play for their own
 *    channels) or template approval-status updates ("message_template_status_update", correlated
 *    to ordo_whatsapp_template by Meta's own template id).
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/notification-payload-examples
 */
class Webhook extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    private const string SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * @var array<string, string> Meta's own uppercase message delivery status => MessageLog::STATUS_*
     */
    private const array MESSAGE_STATUS_MAP = [
        'DELIVERED' => MessageLog::STATUS_DELIVERED,
        'FAILED' => MessageLog::STATUS_FAILED,
        'SENT' => MessageLog::STATUS_SENT,
    ];

    /**
     * @var array<string, string> Meta's own uppercase template status => WhatsAppTemplate::STATUS_*
     */
    private const array TEMPLATE_STATUS_MAP = [
        'APPROVED' => WhatsAppTemplate::STATUS_APPROVED,
        'REJECTED' => WhatsAppTemplate::STATUS_REJECTED,
        'DISABLED' => WhatsAppTemplate::STATUS_DISABLED,
    ];

    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly WhatsAppSignatureValidator $signatureValidator,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly MessageLogResource $messageLogResource,
        private readonly WhatsAppTemplateCollectionFactory $whatsAppTemplateCollectionFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource,
        private readonly StatusDowngradeGuard $statusDowngradeGuard,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->getRequest()->isGet()) {
            return $this->handleVerification();
        }

        return $this->handleEvent();
    }

    private function handleVerification(): ResultInterface
    {
        $result = $this->resultRawFactory->create();

        $mode = (string) $this->getRequest()->getParam('hub_mode');
        $verifyToken = (string) $this->getRequest()->getParam('hub_verify_token');
        $challenge = (string) $this->getRequest()->getParam('hub_challenge');
        $expectedToken = $this->config->getWhatsAppWebhookVerifyToken();

        if ($mode !== 'subscribe' || $expectedToken === '' || !hash_equals($expectedToken, $verifyToken)) {
            $this->logger->error('Ordo_Automation: rejected a WhatsApp webhook verification request.');
            return $result->setHttpResponseCode(403)->setContents('');
        }

        return $result->setContents($challenge);
    }

    private function handleEvent(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        $signature = (string) $this->getRequest()->getHeader(self::SIGNATURE_HEADER);
        $rawBody = (string) $this->getRequest()->getContent();

        if (!$this->signatureValidator->isValid($this->config->getWhatsAppAppSecret(), $rawBody, $signature)) {
            $this->logger->error('Ordo_Automation: rejected a WhatsApp webhook call with an invalid signature.');
            return $result->setHttpResponseCode(403)->setData(['ok' => false]);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        foreach ($this->extractChanges($payload) as $change) {
            $this->processChange($change);
        }

        return $result->setData(['ok' => true]);
    }

    /**
     * @param array<mixed> $payload
     * @return array<int, array<mixed>>
     */
    private function extractChanges(array $payload): array
    {
        $changes = [];
        foreach ($this->toArray($payload['entry'] ?? null) as $entry) {
            foreach ($this->toArray($this->toArray($entry)['changes'] ?? null) as $change) {
                if (is_array($change)) {
                    $changes[] = $change;
                }
            }
        }

        return $changes;
    }

    /**
     * @param array<mixed> $change
     */
    private function processChange(array $change): void
    {
        $value = $this->toArray($change['value'] ?? null);

        foreach ($this->toArray($value['statuses'] ?? null) as $status) {
            if (is_array($status)) {
                $this->processMessageStatus($status);
            }
        }

        $templateUpdate = $change['field'] ?? null;
        if ($templateUpdate === 'message_template_status_update') {
            $this->processTemplateStatus($value);
        }
    }

    /**
     * @return array<mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $status
     */
    private function processMessageStatus(array $status): void
    {
        $providerMessageId = (string) ($status['id'] ?? '');
        $metaStatus = strtoupper((string) ($status['status'] ?? ''));
        $ourStatus = self::MESSAGE_STATUS_MAP[$metaStatus] ?? null;

        if ($providerMessageId === '' || $ourStatus === null) {
            return;
        }

        $collection = $this->messageLogCollectionFactory->create();
        $collection->addFieldToFilter('provider_message_id', $providerMessageId);
        /** @var MessageLog $log */
        $log = $collection->getFirstItem();

        if (!$log->getId()) {
            $this->logger->info(sprintf(
                'Ordo_Automation: WhatsApp webhook status update for unknown message id "%s".',
                $providerMessageId
            ));
            return;
        }

        if ($this->statusDowngradeGuard->isDowngrade($log->getStatus(), $ourStatus)) {
            $this->logger->info(sprintf(
                'Ordo_Automation: ignored a WhatsApp webhook status downgrade for message log #%d '
                . '(current status=%s, incoming status=%s) - likely an out-of-order redelivery.',
                (int) $log->getId(),
                $log->getStatus(),
                $ourStatus
            ));

            return;
        }

        $log->setStatus($ourStatus);
        $errors = $this->toArray($this->toArray($status['errors'] ?? null)[0] ?? null)['code'] ?? null;
        $log->setErrorCode($errors !== null ? (string) $errors : null);
        $this->messageLogResource->save($log);
    }

    /**
     * @param array<mixed> $value
     */
    private function processTemplateStatus(array $value): void
    {
        $metaTemplateId = (string) ($value['message_template_id'] ?? '');
        $metaStatus = strtoupper((string) ($value['event'] ?? ''));
        $ourStatus = self::TEMPLATE_STATUS_MAP[$metaStatus] ?? null;

        if ($metaTemplateId === '' || $ourStatus === null) {
            return;
        }

        $collection = $this->whatsAppTemplateCollectionFactory->create();
        $collection->addFieldToFilter('meta_template_id', $metaTemplateId);
        $template = $collection->getFirstItem();

        if (!$template->getId()) {
            $this->logger->info(sprintf(
                'Ordo_Automation: WhatsApp webhook template status update for unknown template id "%s".',
                $metaTemplateId
            ));
            return;
        }

        $template->setStatus($ourStatus);
        $reason = $value['reason'] ?? null;
        $template->setRejectionReason($reason !== null ? (string) $reason : null);
        $this->whatsAppTemplateResource->save($template);
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
