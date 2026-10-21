<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public, unauthenticated (by session) endpoint for an external system (ERP/CRM/PIM) to feed the
 * "webhook_received" campaign trigger - the inbound half of the "Generic outbound webhook action +
 * inbound webhook trigger" candidate feature (ROADMAP.md). Trust boundary is entirely the
 * "X-Ordo-Signature" HMAC-SHA256 signature, same signature-first pattern every other webhook
 * controller in this module uses (Controller\WhatsApp\Webhook, Controller\Sms\StatusCallback) -
 * an invalid or missing signature is rejected with 401 before the body is even decoded, so an
 * unauthenticated caller can never reach the campaign dispatcher.
 *
 * Publishes to the async queue (Model\Queue\CampaignDispatchPublisher), the same path every
 * observer-fed trigger uses (ADR 0001) — the HTTP response to the calling ERP/CRM/PIM never
 * waits on condition/action evaluation. The "ok" response means "accepted and queued," not
 * "campaigns ran" — same contract the other webhook controllers in this module already have.
 */
class Receive extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isWebhookEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['ok' => false]);
        }

        $secret = $this->config->getWebhookInboundSecret();
        $signature = (string) $this->getRequest()->getHeader(WebhookSignatureValidator::SIGNATURE_HEADER);
        $rawBody = (string) $this->getRequest()->getContent();

        if (!$this->signatureValidator->isValid($secret, $rawBody, $signature)) {
            $this->logger->error('Ordo_Automation: rejected an inbound webhook call with an invalid signature.');
            return $result->setHttpResponseCode(401)->setData(['ok' => false]);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        try {
            $this->campaignDispatchPublisher->publish(
                CampaignTriggerInterface::TRIGGER_WEBHOOK_RECEIVED,
                ['webhook_payload' => $payload]
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: publishing the webhook_received trigger failed: %s',
                $e->getMessage()
            ));
            return $result->setHttpResponseCode(500)->setData(['ok' => false]);
        }

        return $result->setData(['ok' => true]);
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
