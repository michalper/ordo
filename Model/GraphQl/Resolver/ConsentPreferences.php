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
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Model\GraphQl\ConsentPreferencesDataMapper;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;

/**
 * GraphQL counterpart of the storefront "Communication Preferences" account page
 * (Controller\Consent\Index) and the REST GET /V1/ordo/consent-preferences/mine route - all
 * three are a thin transport onto ConsentPreferencesManagementInterface::getMyPreferences().
 *
 * Checks CustomerIdResolver itself, the same explicit-guest-check CreditLimit's own resolver
 * already needs: ConsentPreferencesManagement's own UserContextInterface::getUserId() returns 0
 * for a GraphQL guest, not null (ADR 0032's own confirmed quirk), which would otherwise silently
 * build "preferences" for a nonexistent customer id 0 instead of failing - see
 * ConsentPreferencesManagement's own docblock.
 */
class ConsentPreferences implements ResolverInterface
{
    public function __construct(
        private readonly ConsentPreferencesManagementInterface $consentPreferencesManagement,
        private readonly ConsentPreferencesDataMapper $consentPreferencesDataMapper,
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
            $preferences = $this->consentPreferencesManagement->getMyPreferences();
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        }

        return $this->consentPreferencesDataMapper->map($preferences);
    }
}
