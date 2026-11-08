<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * GraphQL counterpart of Controller\Track\Event - same allowed event types, same
 * truncate-to-255-chars rule, same VisitorEventLogger call. visitorId is a required argument
 * here (see schema.graphqls) rather than read from a request param, but the validation is
 * otherwise identical.
 */
class TrackEvent implements ResolverInterface
{
    private const array ALLOWED_EVENT_TYPES = [
        'page_view',
        'product_view',
        'category_view',
        'element_clicked',
        'checkout_start',
    ];
    private const int MAX_KEY_LENGTH = 255;

    public function __construct(
        private readonly VisitorEventLogger $visitorEventLogger,
        private readonly CustomerIdResolver $customerIdResolver,
        private readonly Config $config
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
        if (!$this->config->isTrackingEnabled()) {
            return ['ok' => false, 'reason' => 'tracking_disabled'];
        }

        $visitorId = isset($args['visitorId']) && is_string($args['visitorId']) ? $args['visitorId'] : '';
        $eventType = isset($args['eventType']) && is_string($args['eventType']) ? $args['eventType'] : '';
        $eventKey = $args['eventKey'] ?? null;
        $eventKey = is_scalar($eventKey) ? (string) $eventKey : null;

        if ($visitorId === '' || !in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            return ['ok' => false, 'reason' => 'invalid_payload'];
        }

        $eventKey = $eventKey !== null ? substr($eventKey, 0, self::MAX_KEY_LENGTH) : null;
        $customerId = $this->customerIdResolver->resolveCustomerId($context);

        $this->visitorEventLogger->log($visitorId, $eventType, $eventKey, $customerId);

        return ['ok' => true];
    }
}
