<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\Data\CustomerInterface;

/**
 * CustomerInterface::getStoreId() has no return type on the interface itself
 * (Api/Data/CustomerInterface.php declares `public function getStoreId();` with no hint) - in
 * practice it can come back as a numeric string, not strictly int|null, since the underlying
 * \Magento\Customer\Model\Data\Customer just returns whatever raw value its data array holds.
 * Confirmed the hard way: a real integration-test customer repository fetch threw "must be of
 * type ?int, string given" against every call site that assumed int|null directly. A null
 * getStoreId() (no store on record) must stay null, not become (int) null = 0 - 0 is a real,
 * different store id, not "use the ambient default store" like every
 * StoreManagerInterface::getStore($storeId)/Helper\Config::is*Enabled($storeId) caller here
 * expects null to mean.
 */
class CustomerStoreResolver
{
    public function resolveStoreId(CustomerInterface $customer): ?int
    {
        $storeId = $customer->getStoreId();

        return $storeId !== null ? (int) $storeId : null;
    }
}
