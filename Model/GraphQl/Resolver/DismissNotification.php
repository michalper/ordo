<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;

/**
 * GraphQL counterpart of Controller\Track\DismissNotification - same ownership check
 * (Notification::belongsTo()) before setting read_at, so a caller can't guess an id and dismiss
 * someone else's notification.
 */
class DismissNotification implements ResolverInterface
{
    public function __construct(
        private readonly NotificationFactory $notificationFactory,
        private readonly NotificationResource $notificationResource,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $notificationId = isset($args['notificationId']) ? (int) $args['notificationId'] : 0;
        if ($notificationId <= 0) {
            return ['ok' => false];
        }

        $visitorId = isset($args['visitorId']) && is_string($args['visitorId']) ? $args['visitorId'] : '';
        $customerId = $this->customerIdResolver->resolveCustomerId($context);

        $notification = $this->notificationFactory->create();
        $this->notificationResource->load($notification, $notificationId);

        if (!$notification->getId() || !$notification->belongsTo($customerId, $visitorId)) {
            return ['ok' => false];
        }

        $notification->setReadAt(date('Y-m-d H:i:s'));
        $this->notificationResource->save($notification);

        return ['ok' => true];
    }
}
