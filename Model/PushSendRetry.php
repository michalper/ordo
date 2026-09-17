<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;

/**
 * A single send_push subscription send whose SendRetrier in-process retries were all exhausted
 * and is now queued for a persisted retry with backoff - see etc/db_schema.xml's
 * ordo_push_send_retry comment and Model\Push\PushSendRetryQueue / Cron\RetryFailedPushSends for
 * how these are created, claimed and eventually resolved. Internal implementation detail, same
 * "not a first-class thing to author" reasoning as MessageSendRetry.
 */
class PushSendRetry extends AbstractModel
{
    use RetryRecordFieldsTrait;

    public const ENTITY_ID = 'entity_id';
    public const SUBSCRIPTION_ID = 'subscription_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const VARIANT = 'variant';
    public const PAYLOAD = 'payload';
    public const ATTEMPTS = 'attempts';
    public const LAST_ERROR = 'last_error';
    public const NEXT_RETRY_AT = 'next_retry_at';

    protected function _construct(): void
    {
        $this->_init(PushSendRetryResource::class);
    }

    public function getSubscriptionId(): int
    {
        return (int) $this->getData(self::SUBSCRIPTION_ID);
    }

    public function setSubscriptionId(int $subscriptionId): self
    {
        $this->setData(self::SUBSCRIPTION_ID, $subscriptionId);
        return $this;
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

    public function getCampaignId(): ?int
    {
        $value = $this->getData(self::CAMPAIGN_ID);
        return $value === null ? null : (int) $value;
    }

    public function setCampaignId(?int $campaignId): self
    {
        $this->setData(self::CAMPAIGN_ID, $campaignId);
        return $this;
    }

    public function getVariant(): ?string
    {
        $value = $this->getData(self::VARIANT);
        return $value === null ? null : (string) $value;
    }

    public function setVariant(?string $variant): self
    {
        $this->setData(self::VARIANT, $variant);
        return $this;
    }

    public function getPayload(): string
    {
        return (string) $this->getData(self::PAYLOAD);
    }

    public function setPayload(string $payload): self
    {
        $this->setData(self::PAYLOAD, $payload);
        return $this;
    }
}
