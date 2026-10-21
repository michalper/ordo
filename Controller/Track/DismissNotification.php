<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated POST endpoint the frontend JS tracker calls when the visitor
 * dismisses a persistent notification banner - the only way an ordo_notification row's
 * read_at ever gets set (see Controller\Track\Notification's own doc on why it never claims
 * anything itself).
 *
 * Ownership is checked before dismissing (VisitorIdentityFieldsTrait::belongsTo()) - a request
 * can only dismiss a notification that actually belongs to the requester's own
 * customer_id/visitor_id, so a visitor can't guess an id and dismiss someone else's notification.
 */
class DismissNotification extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly NotificationFactory $notificationFactory,
        private readonly NotificationResource $notificationResource,
        private readonly VisitorIdentityResolver $visitorIdentityResolver
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $notificationId = (int) $this->getRequest()->getParam('notification_id');
        if ($notificationId <= 0) {
            return $result->setData(['ok' => false]);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        $notification = $this->notificationFactory->create();
        $this->notificationResource->load($notification, $notificationId);

        if (!$notification->getId() || !$notification->belongsTo($customerId, $visitorId)) {
            return $result->setData(['ok' => false]);
        }

        $notification->setReadAt(date('Y-m-d H:i:s'));
        $this->notificationResource->save($notification);

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
