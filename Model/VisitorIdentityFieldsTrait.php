<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The `customer_id`/`visitor_id` pair is identical, field-for-field, across every model holding
 * one row of this module's anonymous-then-stitched-to-customer identity (Notification,
 * PendingPopup, SurveyPrompt, PriceWatch\PriceWatchSubscription): a row is written against a
 * visitor_id while nobody is logged in, and Observer\StitchVisitorIdentity fills in the
 * customer_id later, so both columns are nullable and either one can be the only identity a row
 * has. A trait rather than a shared base class for the same reason RetryRecordFieldsTrait is one
 * - these otherwise extend Magento's AbstractModel directly and share no other behavior worth a
 * base class for. Each consuming class must still declare its own CUSTOMER_ID/VISITOR_ID
 * constants (same value, `self::` resolves per-class).
 *
 * Model\PushSubscription deliberately does NOT use this: its own setCustomerId() takes a
 * non-nullable `int`, not `?int`, so adopting the trait would widen what that setter accepts.
 * Whether that difference is intentional is a separate question from this extraction.
 */
trait VisitorIdentityFieldsTrait
{
    public function getCustomerId(): ?int
    {
        $value = $this->getData(self::CUSTOMER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setCustomerId(?int $customerId): self
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getVisitorId(): ?string
    {
        $value = $this->getData(self::VISITOR_ID);
        return $value === null ? null : (string) $value;
    }

    public function setVisitorId(?string $visitorId): self
    {
        $this->setData(self::VISITOR_ID, $visitorId);
        return $this;
    }
}
