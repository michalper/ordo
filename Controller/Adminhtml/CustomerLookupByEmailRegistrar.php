<?php

declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;

/**
 * Shared "resolve a customer by email, register its id/email onto the page's Registry under
 * this feature's own key prefix" - Customer360\Index and Gdpr\Index each had this byte-for-byte
 * duplicated (differing only in which registry keys), both being a search-by-email admin tool
 * used one customer at a time, not a bulk grid.
 */
class CustomerLookupByEmailRegistrar
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Registry $registry
    ) {
    }

    /**
     * @param string $email already known non-blank by the caller - this class doesn't decide
     *   whether a blank email is worth reporting, only whether a given one resolves.
     * @return CustomerInterface|null null when no customer matches $email, so the caller can
     *   decide how to report that (e.g. an admin messageManager error).
     */
    public function resolveAndRegister(string $email, string $registryKeyPrefix): ?CustomerInterface
    {
        try {
            $customer = $this->customerRepository->get($email);
        } catch (NoSuchEntityException) {
            return null;
        }

        $this->registry->register($registryKeyPrefix . '_customer_id', (int) $customer->getId());
        $this->registry->register($registryKeyPrefix . '_customer_email', $customer->getEmail());

        return $customer;
    }
}
