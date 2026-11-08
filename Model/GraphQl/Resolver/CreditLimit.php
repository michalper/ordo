<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\CreditLimitManagementInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;

/**
 * GraphQL counterpart of the existing GET /V1/ordo/credit-limit/mine REST route - same
 * CreditLimitManagementInterface::getMyStatus() service, a second transport onto it. Identity
 * for that call comes from its own DI-injected UserContextInterface, confirmed (against a live
 * instance, not assumed) to be populated correctly for a GraphQL customer-token request too -
 * see ADR 0032.
 *
 * Unlike the REST route (gated by webapi.xml's resource="self" ACL before the service method
 * ever runs), a GraphQL @resolver field has no such built-in gate, and
 * getMyStatus()'s own `$customerId === null` guard doesn't catch a guest - UserContextInterface
 * returns 0 for a guest, not null (confirmed live: a guest request fell through to a confusing
 * "No such entity with customerId = 0" instead of an authorization error). So this resolver
 * checks for a guest itself first, the same explicit-check convention Magento's own core
 * customer-aware resolvers use (e.g. CustomerGraphQl\Model\Resolver\Customer).
 */
class CreditLimit implements ResolverInterface
{
    public function __construct(
        private readonly CreditLimitManagementInterface $creditLimitManagement,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
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
        if ($this->customerIdResolver->resolveCustomerId($context) === null) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        try {
            $status = $this->creditLimitManagement->getMyStatus();
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        }

        return [
            'credit_limit' => $status->getCreditLimit(),
            'used_credit' => $status->getUsedCredit(),
            'available_credit' => $status->getAvailableCredit(),
            'utilization_percent' => $status->getUtilizationPercent(),
        ];
    }
}
