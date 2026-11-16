<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;

/**
 * Shared byte-for-byte across AssignLeadRoutingRule and EvaluateCustomerScoreRules: both read an
 * event's "customer" data key and need an int customer_id out of it, but that value's concrete
 * type varies by which Magento event fired it - customer_register_success carries the legacy
 * Magento\Customer\Model\Customer (a DataObject, not CustomerInterface), while
 * customer_save_after's own "customer" data can be either, depending on caller. A trait, not a
 * shared base class: the two observers have no other behavior in common (one does lead routing,
 * the other demographic scoring) and neither otherwise extends anything, so forcing an "is-a"
 * relationship between them for this one fragment would be artificial.
 */
trait ResolvesEventCustomerIdTrait
{
    private function resolveEventCustomerId(mixed $eventCustomer): int
    {
        $rawId = match (true) {
            $eventCustomer instanceof DataObject => $eventCustomer->getId(),
            $eventCustomer instanceof CustomerInterface => $eventCustomer->getId(),
            default => null,
        };

        return is_scalar($rawId) ? (int) $rawId : 0;
    }
}
