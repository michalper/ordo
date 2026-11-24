<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Customer;

use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;

/**
 * `ordo_credit_limit`/`ordo_order_spend_limit`/`ordo_approval_admin_email` are customer EAV
 * attributes meant to be admin-only (Setup\Patch\AbstractCustomerAttributePatch sets
 * `used_in_forms = ['adminhtml_customer']`), gating Plugin\Quote\BlockOverLimitCheckout and
 * Observer\HoldOrderForApproval - but `used_in_forms` only controls which admin UI FORM renders
 * a field, it is not an ACL and CustomerRepository::save() enforces no allowlist on which
 * custom_attributes an authenticated caller may set. Found during a full-repo security review:
 * Magento core's own self-service `PUT /V1/customers/me` (`resource ref="self"`) would let any
 * logged-in customer set `ordo_credit_limit`/`ordo_order_spend_limit` to 0 and
 * `ordo_approval_admin_email` to '' - both checkout gates treat an empty/zero value as "no
 * limit configured", so either call defeats its own control entirely, with no admin involved.
 *
 * Strips these 3 attribute codes from the incoming save whenever the request isn't coming from
 * a logged-in admin session - Magento's own EAV save only writes attributes actually present on
 * the model, so omitting one here leaves whatever is already persisted untouched, the same way
 * a self-service account-edit form (which never renders these admin-only fields at all) already
 * safely coexists with them.
 */
class ProtectAdminOnlyCustomerAttributesPlugin
{
    private const array PROTECTED_ATTRIBUTE_CODES = [
        'ordo_credit_limit',
        'ordo_order_spend_limit',
        'ordo_approval_admin_email',
    ];

    public function __construct(
        private readonly BackendAuthSession $authSession
    ) {
    }

    /**
     * @return array{0: CustomerInterface, 1: ?string}
     */
    public function beforeSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer,
        ?string $passwordHash = null
    ): array {
        if ($this->authSession->getUser() !== null) {
            return [$customer, $passwordHash];
        }

        $customer->setCustomAttributes(array_values(array_filter(
            $customer->getCustomAttributes() ?? [],
            static fn ($attribute): bool => !in_array($attribute->getAttributeCode(), self::PROTECTED_ATTRIBUTE_CODES, true)
        )));

        return [$customer, $passwordHash];
    }
}
