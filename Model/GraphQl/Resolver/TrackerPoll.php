<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\Track\TrackerPollReader;

/**
 * GraphQL counterpart of Controller\Track\Poll - identical {popup, notifications, survey} shape
 * and semantics. The actual resolution logic lives in Model\Track\TrackerPollReader, shared with
 * that controller (see ADR 0032/0033) - this resolver's only own job is resolving identity the
 * GraphQL customer-token way. See that controller's own docblock for why the three concerns were
 * merged into one call in the first place (ADR 0024).
 */
class TrackerPoll implements ResolverInterface
{
    public function __construct(
        private readonly TrackerPollReader $trackerPollReader,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{visitorId?: ?string}|null $args
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

        return $this->trackerPollReader->resolve($customerId, $visitorId);
    }
}
