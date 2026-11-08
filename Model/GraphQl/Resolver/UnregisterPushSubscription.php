<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\Push\PushSubscriptionManager;

/**
 * GraphQL counterpart of Controller\Track\UnregisterPushSubscription - same ownership check
 * (PushSubscription::belongsTo(), done inside PushSubscriptionManager::unregister() itself)
 * before deleting, so a caller can't remove someone else's subscription by guessing its
 * endpoint. Identity comes from CustomerIdResolver rather than
 * Model\Track\VisitorIdentityResolver's cookie read; see ADR 0032/0034.
 */
class UnregisterPushSubscription implements ResolverInterface
{
    public function __construct(
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{endpoint?: string, visitorId?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $endpoint = trim($args['endpoint'] ?? '');
        if ($endpoint === '') {
            return ['ok' => false];
        }

        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        $visitorId = $args['visitorId'] ?? '';

        $this->pushSubscriptionManager->unregister($endpoint, $customerId, $visitorId);

        return ['ok' => true];
    }
}
