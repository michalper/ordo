<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;

/**
 * GraphQL counterpart of Api\Model\PriceWatch\PriceWatchSubscriptionManager::unregister() - no
 * existing REST/controller transport calls this one yet (Controller\Track\RegisterPriceWatch
 * only registers), so this resolver is the first customer-facing surface for it. Identity comes
 * from CustomerIdResolver, same as RegisterPriceWatch; see ADR 0034.
 */
class UnregisterPriceWatch implements ResolverInterface
{
    public function __construct(
        private readonly PriceWatchSubscriptionManager $priceWatchSubscriptionManager,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{productId?: int, watchType?: string, visitorId?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        $visitorId = $args['visitorId'] ?? '';
        $productId = $args['productId'] ?? 0;
        $watchType = $args['watchType'] ?? '';

        if ($productId > 0 && $watchType !== '') {
            $this->priceWatchSubscriptionManager->unregister(
                $productId,
                $watchType,
                $customerId,
                $visitorId !== '' ? $visitorId : null
            );
        }

        return ['ok' => true];
    }
}
