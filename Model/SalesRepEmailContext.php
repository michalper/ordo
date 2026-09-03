<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;

/**
 * Shared signature block for every automated email in this module — reorder, offer expiry,
 * credit limit, and win-back all call this instead of each inventing their own "who signs
 * this email" logic. Falls back to the store name when no rep is assigned, so templates never
 * have to special-case a missing value.
 */
class SalesRepEmailContext
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array{sender_name: string, sender_email: string, sender_phone: string, has_assigned_rep: bool}
     */
    public function getForCustomer(int $customerId): array
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return $this->getFallback();
        }

        $name = $this->getAttributeValue($customer, AddSalesRepAttributes::ATTRIBUTE_REP_NAME);
        $email = $this->getAttributeValue($customer, AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL);
        $phone = $this->getAttributeValue($customer, AddSalesRepAttributes::ATTRIBUTE_REP_PHONE);

        if ($name === '' || $email === '') {
            return $this->getFallback();
        }

        return [
            'sender_name' => $name,
            'sender_email' => $email,
            'sender_phone' => $phone,
            'has_assigned_rep' => true,
        ];
    }

    private function getAttributeValue($customer, string $code): string
    {
        $attribute = $customer->getCustomAttribute($code);
        return $attribute ? (string) $attribute->getValue() : '';
    }

    /**
     * @return array{sender_name: string, sender_email: string, sender_phone: string, has_assigned_rep: bool}
     */
    private function getFallback(): array
    {
        try {
            $storeName = (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            $storeName = '';
        }

        return [
            'sender_name' => $storeName !== '' ? $storeName . ' Team' : 'our team',
            'sender_email' => '',
            'sender_phone' => '',
            'has_assigned_rep' => false,
        ];
    }
}
