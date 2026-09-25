<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ReferralCode as ReferralCodeResource;

/**
 * One customer's shareable referral code - plain data holder, same convention as
 * PushSubscription/CustomerConsent (real getters/setters, not bare magic __call).
 */
class ReferralCode extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CODE = 'code';
    public const CREATED_AT = 'created_at';

    protected function _construct(): void
    {
        $this->_init(ReferralCodeResource::class);
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData(self::CUSTOMER_ID);
    }

    public function setCustomerId(int $customerId): self
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::CODE);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::CODE, $code);
        return $this;
    }
}
