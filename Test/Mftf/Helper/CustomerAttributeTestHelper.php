<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Sets a customer's own varchar EAV attribute directly - there is no admin UI/REST path this
 * suite already drives for a module-added customer attribute like "ordo_timezone"
 * (Setup\Patch\Data\AddCustomerTimezoneAttribute), same "no MFTF-reachable write path" reasoning
 * as every other direct-DB helper in this suite. Pins the customer to a known, unambiguous
 * timezone (UTC) so a test computing "N hours from now" doesn't also have to know or guess this
 * install's own store-default timezone (CustomerTimezoneResolver's fallback when the attribute
 * is unset).
 */
class CustomerAttributeTestHelper extends Helper
{
    use DbConnectionTrait;

    public function setVarcharAttribute(
        int $customerId,
        string $attributeCode,
        string $value
    ): void {
        $pdo = $this->resolveDbConnection();

        $attributeIdStatement = $pdo->prepare(
            "SELECT ea.attribute_id FROM eav_attribute ea "
            . "INNER JOIN eav_entity_type eet ON eet.entity_type_id = ea.entity_type_id "
            . "WHERE eet.entity_type_code = 'customer' AND ea.attribute_code = :attribute_code"
        );
        $attributeIdStatement->execute(['attribute_code' => $attributeCode]);
        $attributeId = $attributeIdStatement->fetchColumn();

        if ($attributeId === false) {
            throw new \RuntimeException("No customer EAV attribute found for code \"{$attributeCode}\".");
        }

        $statement = $pdo->prepare(
            'INSERT INTO customer_entity_varchar (attribute_id, entity_id, value) '
            . 'VALUES (:attribute_id, :entity_id, :value) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $statement->execute(['attribute_id' => $attributeId, 'entity_id' => $customerId, 'value' => $value]);
    }
}
