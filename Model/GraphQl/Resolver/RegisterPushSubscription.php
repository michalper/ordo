<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Push\PushSubscriptionManager;

/**
 * GraphQL counterpart of Controller\Track\RegisterPushSubscription - same endpoint validation
 * (Model\Push\PushEndpointValidator, the SSRF defense - see that controller's own docblock) and
 * the same PushSubscriptionManager::register() call. Identity comes from CustomerIdResolver
 * rather than Model\Track\VisitorIdentityResolver's cookie read; visitorId is an explicit
 * argument for the same reason (see ADR 0032/0034).
 */
class RegisterPushSubscription implements ResolverInterface
{
    public function __construct(
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PushEndpointValidator $pushEndpointValidator,
        private readonly CustomerIdResolver $customerIdResolver,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{endpoint?: string, p256dh?: string, auth?: string, visitorId?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->config->isPushEnabled()) {
            return ['ok' => false, 'reason' => 'push_disabled'];
        }

        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        $visitorId = $args['visitorId'] ?? '';
        $endpoint = trim($args['endpoint'] ?? '');
        $p256dh = trim($args['p256dh'] ?? '');
        $auth = trim($args['auth'] ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '' || ($customerId === null && $visitorId === '')) {
            return ['ok' => false, 'reason' => 'invalid_payload'];
        }

        if (!$this->pushEndpointValidator->isAllowed($endpoint)) {
            return ['ok' => false, 'reason' => 'invalid_endpoint'];
        }

        $this->pushSubscriptionManager->register(
            $endpoint,
            $p256dh,
            $auth,
            $customerId,
            $visitorId !== '' ? $visitorId : null
        );

        return ['ok' => true];
    }
}
