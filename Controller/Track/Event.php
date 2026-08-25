<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Public, unauthenticated endpoint the frontend JS tracker (view/frontend/web/js/tracker.js)
 * posts to. No CSRF token — this is meant to be callable by anonymous visitors who have no
 * session/form key yet, which is exactly the same trust model as any third-party "pixel"
 * tracking endpoint (SalesManago/iPresso's own JS SDKs work the same way).
 */
class Event extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ALLOWED_EVENT_TYPES = ['page_view', 'product_view', 'category_view'];
    private const MAX_KEY_LENGTH = 255;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly VisitorEventLogger $visitorEventLogger,
        private readonly CustomerSession $customerSession,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isTrackingEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'tracking_disabled']);
        }

        $visitorId = (string) $this->getRequest()->getParam('visitor_id');
        $eventType = (string) $this->getRequest()->getParam('event_type');
        $eventKey = $this->getRequest()->getParam('event_key');

        if ($visitorId === '' || !in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        $eventKey = $eventKey !== null ? substr((string) $eventKey, 0, self::MAX_KEY_LENGTH) : null;
        $customerId = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null;

        $this->visitorEventLogger->log($visitorId, $eventType, $eventKey, $customerId);

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
