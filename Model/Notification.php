<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;

/**
 * A persistent, non-modal notification queued by a campaign's "notify" action — see
 * etc/db_schema.xml's ordo_notification comment for how this differs from PendingPopup (never
 * claimed-and-gone; stays visible across every poll until explicitly dismissed or expired).
 * Internal implementation detail of the on-site channel, not exposed via its own REST resource —
 * same reasoning as PendingPopup/CampaignScheduledAction.
 */
class Notification extends AbstractModel
{
    use VisitorIdentityFieldsTrait;

    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const VISITOR_ID = 'visitor_id';
    public const HEADLINE = 'headline';
    public const BODY = 'body';
    public const CTA_LABEL = 'cta_label';
    public const CTA_URL = 'cta_url';
    public const READ_AT = 'read_at';
    public const EXPIRES_AT = 'expires_at';

    protected function _construct(): void
    {
        $this->_init(NotificationResource::class);
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

    public function getReadAt(): ?string
    {
        $value = $this->getData(self::READ_AT);
        return $value === null ? null : (string) $value;
    }

    public function setReadAt(?string $readAt): self
    {
        $this->setData(self::READ_AT, $readAt);
        return $this;
    }

    public function setExpiresAt(?string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
        return $this;
    }
}
