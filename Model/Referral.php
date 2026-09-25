<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Referral as ReferralResource;

/**
 * One successful referral signup - a referred customer who registered using another customer's
 * referral code (Model\ReferralManager::recordSignup()). Starts STATUS_PENDING, becomes
 * STATUS_CONVERTED the first time the referred customer places an order
 * (Observer\DispatchReferralConvertedCampaigns).
 */
class Referral extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const REFERRER_CUSTOMER_ID = 'referrer_customer_id';
    public const REFERRED_CUSTOMER_ID = 'referred_customer_id';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const CONVERTED_AT = 'converted_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONVERTED = 'converted';

    protected function _construct(): void
    {
        $this->_init(ReferralResource::class);
    }

    public function getReferrerCustomerId(): int
    {
        return (int) $this->getData(self::REFERRER_CUSTOMER_ID);
    }

    public function setReferrerCustomerId(int $customerId): self
    {
        $this->setData(self::REFERRER_CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getReferredCustomerId(): int
    {
        return (int) $this->getData(self::REFERRED_CUSTOMER_ID);
    }

    public function setReferredCustomerId(int $customerId): self
    {
        $this->setData(self::REFERRED_CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::STATUS, $status);
        return $this;
    }
}
