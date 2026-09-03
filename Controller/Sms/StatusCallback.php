<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Sms;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use Psr\Log\LoggerInterface;
use Twilio\Security\RequestValidator;

/**
 * Public, unauthenticated endpoint Twilio POSTs delivery-status updates to (the "statusCallback"
 * URL passed on every send — see Model\Sms\TwilioSmsSender::getStatusCallbackUrl()). No CSRF
 * token — same trust model as Controller\Track\Event.php ("callable by an anonymous third party
 * with no session/form key"), except here the actual trust boundary is the X-Twilio-Signature
 * check below, which is mandatory and happens before anything else: without it, anyone who found
 * this URL could POST fake delivery statuses for arbitrary phone numbers into ordo_message_log.
 */
class StatusCallback extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly CallbackUrlBuilder $callbackUrlBuilder,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly MessageLogResource $messageLogResource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $signature = (string) $this->getRequest()->getHeader('X-Twilio-Signature');
        $params = $this->getRequest()->getPostValue();
        $params = is_array($params) ? $params : [];

        $validator = new RequestValidator($this->config->getTwilioAuthToken());
        $callbackUrl = $this->callbackUrlBuilder->getSmsStatusCallbackUrl();
        if ($signature === '' || !$validator->validate($signature, $callbackUrl, $params)) {
            $this->logger->error(
                'Ordo_Automation: rejected an SMS status callback with an invalid X-Twilio-Signature.'
            );

            return $result->setHttpResponseCode(403)->setData(['ok' => false]);
        }

        $messageSid = (string) ($params['MessageSid'] ?? '');
        $status = (string) ($params['MessageStatus'] ?? '');
        $errorCode = isset($params['ErrorCode']) ? (string) $params['ErrorCode'] : null;

        if ($messageSid === '' || $status === '') {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        $collection = $this->messageLogCollectionFactory->create();
        $collection->addFieldToFilter('provider_message_id', $messageSid);
        $log = $collection->getFirstItem();

        if (!$log->getId()) {
            // Twilio retries indefinitely on a non-2xx response — an unrecognized MessageSid
            // will never resolve on retry, so this still returns 200 rather than making Twilio
            // hammer an endpoint that can't do anything useful with the callback.
            $this->logger->info(sprintf(
                'Ordo_Automation: SMS status callback for unknown MessageSid "%s" (status=%s).',
                $messageSid,
                $status
            ));

            return $result->setData(['ok' => true]);
        }

        $log->setStatus($status);
        $log->setErrorCode($errorCode);
        $this->messageLogResource->save($log);

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
