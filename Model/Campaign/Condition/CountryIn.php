<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;

/**
 * Params: {"countries": "US,PL,DE"} - a comma-separated list of ISO 3166-1 alpha-2 country_id
 * values, same free-text-list shape Action\SendWhatsApp::parseParams() already uses for its own
 * "params" field (no existing field type in campaign-flow-editor.js renders a real multi-value
 * picker - a full country <select multiple>/chip-editor widget would be considerably more code
 * for the same result here, and an admin typing 2-letter codes directly is no heavier than
 * typing a SKU or category ID, which every other free-text condition param already asks for).
 *
 * Context must include "customer_id" (int). Matches a customer whose default billing address is
 * in one of the given countries - read from customer_grid_flat.billing_country_id, the same
 * denormalized, indexed column Magento's own admin customer grid filters/searches on, rather
 * than resolving the customer's default-billing address_id through customer_entity/
 * customer_address_entity by hand. Kept current by Magento's own customer_grid indexer
 * (reindexed on customer save, same as every other admin-grid-backed lookup in core) - not a gap
 * specific to this condition.
 *
 * No existing condition type covered this: a store running campaigns/segments across more than
 * one country/region had no way to target or exclude by it at all.
 */
class CountryIn implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $countries = $this->readCountries($params);

        if ($customerId <= 0 || $countries === []) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('customer_grid_flat');

        $billingCountry = $connection->fetchOne(
            $connection->select()
                ->from($table, 'billing_country_id')
                ->where('entity_id = ?', $customerId)
        );

        // No explicit "no row found" check needed - fetchOne()'s false/null sentinel casts to
        // '', which never matches a real 2-letter country code.
        return in_array((string) $billingCountry, $countries, true);
    }

    public function resolveCustomerIds(array $params): array
    {
        $countries = $this->readCountries($params);
        if ($countries === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('customer_grid_flat');

        return array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()
                    ->from($table, 'entity_id')
                    ->where('billing_country_id IN (?)', $countries)
            )
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function readCountries(array $params): array
    {
        $countriesCsv = trim((string) ($params['countries'] ?? ''));
        if ($countriesCsv === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $country) => strtoupper(trim($country)), explode(',', $countriesCsv)),
            static fn (string $country) => $country !== ''
        )));
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'countries',
                'label' => (string) __('Countries (comma-separated ISO codes)'),
                'notice' => (string) __('2-letter codes, e.g. US,PL,DE - matches the customer\'s default billing address.'),
            ],
        ];
    }
}
