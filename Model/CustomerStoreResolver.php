<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\Data\CustomerInterface;

/**
 * CustomerInterface::getStoreId()/getWebsiteId() have no return type on the interface itself
 * (Api/Data/CustomerInterface.php declares both as `public function getXId();` with no hint) -
 * in practice either can come back as a numeric string, not strictly int|null, since the
 * underlying \Magento\Customer\Model\Data\Customer just returns whatever raw value its data
 * array holds. Confirmed the hard way for getStoreId(): a real integration-test customer
 * repository fetch threw "must be of type ?int, string given" against every call site that
 * assumed int|null directly (see ADR 0013). A null value (no store/website on record) must stay
 * null, not become (int) null = 0 - 0 is a real, different id, not "unscoped"/"use the ambient
 * default" like every caller of either resolver method here expects null to mean.
 */
class CustomerStoreResolver
{
    public function resolveStoreId(CustomerInterface $customer): ?int
    {
        $storeId = $customer->getStoreId();

        return $storeId !== null ? (int) $storeId : null;
    }

    public function resolveWebsiteId(CustomerInterface $customer): ?int
    {
        $websiteId = $customer->getWebsiteId();

        return $websiteId !== null ? (int) $websiteId : null;
    }
}
