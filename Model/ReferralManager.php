<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Ordo\Automation\Model\ResourceModel\Referral as ReferralResource;
use Ordo\Automation\Model\ResourceModel\Referral\Collection as ReferralCollection;
use Ordo\Automation\Model\ResourceModel\Referral\CollectionFactory as ReferralCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReferralCode as ReferralCodeResource;
use Ordo\Automation\Model\ResourceModel\ReferralCode\Collection as ReferralCodeCollection;
use Ordo\Automation\Model\ResourceModel\ReferralCode\CollectionFactory as ReferralCodeCollectionFactory;

/**
 * Orchestrates the referral program's three moments: a customer getting their own shareable code
 * (getOrCreateCode), a new customer registering with someone else's code (recordSignup), and that
 * referred customer's first order converting the referral (markConvertedAndGetReferrer) - see
 * Controller\Referral\Track, Observer\RedeemReferralCode, and
 * Observer\DispatchReferralConvertedCampaigns respectively.
 */
class ReferralManager
{
    public function __construct(
        private readonly ReferralCodeCollectionFactory $referralCodeCollectionFactory,
        private readonly ReferralCodeResource $referralCodeResource,
        private readonly ReferralCodeFactory $referralCodeFactory,
        private readonly ReferralCodeGenerator $referralCodeGenerator,
        private readonly ReferralCollectionFactory $referralCollectionFactory,
        private readonly ReferralResource $referralResource,
        private readonly ReferralFactory $referralFactory
    ) {
    }

    public function getOrCreateCode(int $customerId): string
    {
        $existing = $this->findCodeByCustomer($customerId);
        if ($existing instanceof ReferralCode) {
            return $existing->getCode();
        }

        $code = $this->referralCodeGenerator->generateUnique(
            fn (string $candidate): bool => $this->findCodeByValue($candidate) instanceof ReferralCode
        );

        $referralCode = $this->referralCodeFactory->create();
        $referralCode->setCustomerId($customerId);
        $referralCode->setCode($code);

        try {
            $this->referralCodeResource->save($referralCode);
        } catch (AlreadyExistsException $e) {
            // Lost a race against a concurrent getOrCreateCode() call for this same customer
            // between the check above and this save - the unique constraint on customer_id means
            // this is by far the likeliest cause (a code-value collision is vanishingly rare
            // given generateUnique()'s own check just above), so the row the other request
            // already created covers it; only a genuine code-value collision falls through to
            // the rethrow.
            $existing = $this->findCodeByCustomer($customerId);
            if ($existing instanceof ReferralCode) {
                return $existing->getCode();
            }

            throw $e;
        }

        return $code;
    }

    public function resolveReferrerCustomerId(string $code): ?int
    {
        $referralCode = $this->findCodeByValue($code);
        return $referralCode?->getCustomerId();
    }

    /**
     * Records a successful referral signup. No-op (returns false) for a self-referral, or when
     * the referred customer already has a referral row - a customer can only ever be referred
     * once, by whichever code they registered with first.
     */
    public function recordSignup(int $referrerCustomerId, int $referredCustomerId): bool
    {
        if ($referrerCustomerId === $referredCustomerId) {
            return false;
        }

        /** @var ReferralCollection $collection */
        $collection = $this->referralCollectionFactory->create();
        $collection->addReferredCustomerFilter($referredCustomerId);
        if ($collection->getSize() > 0) {
            return false;
        }

        $referral = $this->referralFactory->create();
        $referral->setReferrerCustomerId($referrerCustomerId);
        $referral->setReferredCustomerId($referredCustomerId);
        $referral->setStatus(Referral::STATUS_PENDING);
        $this->referralResource->save($referral);

        return true;
    }

    /**
     * Marks the referred customer's pending referral converted and returns who referred them, or
     * null if there is no pending referral for this customer (never referred, already converted,
     * or this is a repeat customer with no referral at all).
     */
    public function markConvertedAndGetReferrer(int $referredCustomerId): ?int
    {
        /** @var ReferralCollection $collection */
        $collection = $this->referralCollectionFactory->create();
        $collection->addReferredCustomerFilter($referredCustomerId);
        $collection->addStatusFilter(Referral::STATUS_PENDING);
        $collection->setPageSize(1);

        /** @var Referral $referral */
        $referral = $collection->getFirstItem();
        if (!$referral->getId()) {
            return null;
        }

        $referral->setStatus(Referral::STATUS_CONVERTED);
        $referral->setData(Referral::CONVERTED_AT, date('Y-m-d H:i:s'));
        $this->referralResource->save($referral);

        return $referral->getReferrerCustomerId();
    }

    private function findCodeByCustomer(int $customerId): ?ReferralCode
    {
        /** @var ReferralCodeCollection $collection */
        $collection = $this->referralCodeCollectionFactory->create();
        $collection->addCustomerFilter($customerId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }

    private function findCodeByValue(string $code): ?ReferralCode
    {
        /** @var ReferralCodeCollection $collection */
        $collection = $this->referralCodeCollectionFactory->create();
        $collection->addCodeFilter($code);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }
}
