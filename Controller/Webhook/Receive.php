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
use Ordo\Automation\Model\CampaignDispatcher;
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
 * Dispatches synchronously (Model\CampaignDispatcher::dispatch() called directly), the same
 * shape Cron\SendAbandonedCartReminders/SendBrowseAbandonmentReminders already use - not routed
 * through the async queue (Model\Queue\CampaignDispatchConsumer) that order/customer/tag events
 * use. That's a deliberate MVP scope cut: a slow/misbehaving campaign chain on this endpoint could
 * make the HTTP response slow for the calling ERP/CRM/PIM, but keeps this first cut simple; moving
 * it onto the existing async publisher is a natural, isolated follow-up.
 */
class Receive extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly CampaignDispatcher $campaignDispatcher,
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
            $this->campaignDispatcher->dispatch(
                CampaignTriggerInterface::TRIGGER_WEBHOOK_RECEIVED,
                ['webhook_payload' => $payload]
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: dispatching the webhook_received trigger failed: %s',
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
