<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;

/**
 * A popup/banner queued by a campaign's "popup" action, waiting to be claimed by
 * Controller\Track\Poll on the target visitor/customer's next poll. Internal implementation
 * detail of the on-site channel, not exposed via its own REST resource — same reasoning as
 * CampaignScheduledAction.
 */
class PendingPopup extends AbstractModel
{
    use VisitorIdentityFieldsTrait;

    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const VISITOR_ID = 'visitor_id';
    public const HEADLINE = 'headline';
    public const BODY = 'body';
    public const CTA_LABEL = 'cta_label';
    public const CTA_URL = 'cta_url';
    public const DELIVERED_AT = 'delivered_at';
    public const EXPIRES_AT = 'expires_at';
    public const REWARD_LABEL = 'reward_label';
    public const COUPON_CODE = 'coupon_code';

    protected function _construct(): void
    {
        $this->_init(PendingPopupResource::class);
    }

    public function getHeadline(): string
    {
        return (string) $this->getData(self::HEADLINE);
    }

    public function setHeadline(string $headline): self
    {
        $this->setData(self::HEADLINE, $headline);
        return $this;
    }

    public function getBody(): ?string
    {
        $value = $this->getData(self::BODY);
        return $value === null ? null : (string) $value;
    }

    public function setBody(?string $body): self
    {
        $this->setData(self::BODY, $body);
        return $this;
    }

    public function getCtaLabel(): ?string
    {
        $value = $this->getData(self::CTA_LABEL);
        return $value === null ? null : (string) $value;
    }

    public function setCtaLabel(?string $ctaLabel): self
    {
        $this->setData(self::CTA_LABEL, $ctaLabel);
        return $this;
    }

    public function getCtaUrl(): ?string
    {
        $value = $this->getData(self::CTA_URL);
        return $value === null ? null : (string) $value;
    }

    public function setCtaUrl(?string $ctaUrl): self
    {
        $this->setData(self::CTA_URL, $ctaUrl);
        return $this;
    }

    public function getDeliveredAt(): ?string
    {
        $value = $this->getData(self::DELIVERED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setDeliveredAt(?string $deliveredAt): self
    {
        $this->setData(self::DELIVERED_AT, $deliveredAt);
        return $this;
    }

    public function setExpiresAt(?string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
        return $this;
    }

    /**
     * Set only when the popup action was configured with weighted reward_tiers and this is the
     * tier that was randomly picked - see Model\Campaign\Action\ShowPopup. Null for an ordinary
     * static popup.
     */
    public function getRewardLabel(): ?string
    {
        $value = $this->getData(self::REWARD_LABEL);
        return $value === null ? null : (string) $value;
    }

    public function setRewardLabel(?string $rewardLabel): self
    {
        $this->setData(self::REWARD_LABEL, $rewardLabel);
        return $this;
    }

    /**
     * Set only when the winning reward tier had a coupon_rule_id - a tier with none (e.g. a "Try
     * again" tier) leaves this null even though rewardLabel is set.
     */
    public function getCouponCode(): ?string
    {
        $value = $this->getData(self::COUPON_CODE);
        return $value === null ? null : (string) $value;
    }

    public function setCouponCode(?string $couponCode): self
    {
        $this->setData(self::COUPON_CODE, $couponCode);
        return $this;
    }
}
