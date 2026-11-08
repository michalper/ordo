<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;

/**
 * GraphQL counterpart of Controller\Track\RegisterPriceWatch - same validation and
 * PriceWatchSubscriptionManager::register() call. Identity comes from CustomerIdResolver (the
 * GraphQL customer-token context) rather than Model\Track\VisitorIdentityResolver's cookie read
 * (see CustomerIdResolver's own docblock and ADR 0032/0034); visitorId is an explicit argument
 * for the same reason.
 */
class RegisterPriceWatch implements ResolverInterface
{
    private const array VALID_WATCH_TYPES = [
        PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
        PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK,
    ];

    public function __construct(
        private readonly PriceWatchSubscriptionManager $priceWatchSubscriptionManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerIdResolver $customerIdResolver,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{productId?: int, watchType?: string, visitorId?: ?string, email?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->config->isPriceWatchEnabled()) {
            return ['ok' => false, 'reason' => 'price_watch_disabled'];
        }

        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        $visitorId = $args['visitorId'] ?? '';
        $productId = $args['productId'] ?? 0;
        $watchType = $args['watchType'] ?? '';

        if ($productId <= 0
            || !in_array($watchType, self::VALID_WATCH_TYPES, true)
            || ($customerId === null && $visitorId === '')
        ) {
            return ['ok' => false, 'reason' => 'invalid_payload'];
        }

        try {
            $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return ['ok' => false, 'reason' => 'invalid_product'];
        }

        $guestEmail = null;
        if ($customerId === null) {
            $email = trim($args['email'] ?? '');
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'reason' => 'invalid_email'];
                }
                $guestEmail = $email;
            }
        }

        $this->priceWatchSubscriptionManager->register(
            $productId,
            $watchType,
            $customerId,
            $visitorId !== '' ? $visitorId : null,
            $guestEmail
        );

        return ['ok' => true];
    }
}
