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
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\Conversation\InboundMessageProcessor;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use Psr\Log\LoggerInterface;
use Twilio\Security\RequestValidator;

/**
 * Public, unauthenticated endpoint Twilio POSTs an inbound SMS reply to - a SEPARATE webhook
 * config from Controller\Sms\StatusCallback's own URL (see CallbackUrlBuilder::getSmsReplyUrl()'s
 * docblock for why Twilio needs two distinct URLs here). An admin configures this URL once,
 * directly against the sending phone number in the Twilio Console ("A Message Comes In") - this
 * module has no API call that sets it the way statusCallback is passed on every send.
 *
 * Same trust model as StatusCallback: no CSRF token, X-Twilio-Signature verification is the real
 * trust boundary and happens before anything else.
 */
class Reply extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly CallbackUrlBuilder $callbackUrlBuilder,
        private readonly InboundMessageProcessor $inboundMessageProcessor,
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
        $replyUrl = $this->callbackUrlBuilder->getSmsReplyUrl();
        if ($signature === '' || !$validator->validate($signature, $replyUrl, $params)) {
            $this->logger->error(
                'Ordo_Automation: rejected an inbound SMS reply with an invalid X-Twilio-Signature.'
            );

            return $result->setHttpResponseCode(403)->setData(['ok' => false]);
        }

        $from = (string) ($params['From'] ?? '');
        $body = (string) ($params['Body'] ?? '');
        $messageSid = isset($params['MessageSid']) ? (string) $params['MessageSid'] : null;

        if ($from === '') {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        $this->inboundMessageProcessor->process(ConsentChannel::Sms, $from, $body, $messageSid);

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
