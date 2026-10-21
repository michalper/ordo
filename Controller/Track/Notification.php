<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint the frontend JS tracker polls, same trust model as
 * Controller\Track\{Event,Popup} - no CSRF token, callable by an anonymous visitor with no
 * session/form key yet.
 *
 * Unlike Controller\Track\Popup, this does NOT claim/consume anything - every unread, unexpired
 * notification for this target is returned on every poll, so the same set naturally survives a
 * page reload without any client-side storage. Dismissal is a separate, explicit action
 * (Controller\Track\DismissNotification).
 */
class Notification extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly NotificationCollectionFactory $notificationCollectionFactory,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isNotificationEnabled()) {
            return $result->setData(['notifications' => []]);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        if ($this->visitorIdentityResolver->hasNoIdentity($visitorId)) {
            return $result->setData(['notifications' => []]);
        }

        $now = date('Y-m-d H:i:s');
        $collection = $this->notificationCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);

        $notifications = [];
        foreach ($collection as $notification) {
            /** @var \Ordo\Automation\Model\Notification $notification */
            $notifications[] = [
                'id' => (int) $notification->getId(),
                'headline' => $notification->getHeadline(),
                'body' => $notification->getBody(),
                'cta_label' => $notification->getCtaLabel(),
                'cta_url' => $notification->getCtaUrl(),
            ];
        }

        return $result->setData(['notifications' => $notifications]);
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
