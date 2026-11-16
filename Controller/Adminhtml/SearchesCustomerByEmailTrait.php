<?php

declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml;

use Magento\Customer\Api\Data\CustomerInterface;

/**
 * Shared `beforeRender()` for the two admin landing pages that resolve a customer by an `?email=`
 * query param before rendering (Customer360\Index, Gdpr\Index) - both had this byte-for-byte
 * duplicated, differing only in the registry key prefix passed to CustomerLookupByEmailRegistrar.
 * Used alongside RendersGridPageTrait, whose own `beforeRender()` this one takes precedence over.
 *
 * @property CustomerLookupByEmailRegistrar $customerLookup the using class's own constructor-
 *   promoted property - not declared by this trait (a trait can't declare a constructor), but
 *   required by name for this trait's beforeRender() to compile.
 */
trait SearchesCustomerByEmailTrait
{
    /**
     * @return string the registry key prefix for CustomerLookupByEmailRegistrar::resolveAndRegister()
     */
    abstract protected function customerSearchRegistryPrefix(): string;

    protected function beforeRender(): void
    {
        $email = trim((string) $this->getRequest()->getParam('email'));
        if ($email === '') {
            return;
        }

        $resolved = $this->customerLookup->resolveAndRegister($email, $this->customerSearchRegistryPrefix());
        if (!$resolved instanceof CustomerInterface) {
            $this->messageManager->addErrorMessage(__('No customer found for that email.'));
        }
    }
}
