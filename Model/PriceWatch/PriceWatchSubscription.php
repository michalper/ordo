<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\PriceWatch;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;

/**
 * One customer/visitor's price-drop or back-in-stock watch on a single product. Plain data
 * holder with real getters/setters, same convention as PushSubscription/CustomerConsent.
 */
class PriceWatchSubscription extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const VISITOR_ID = 'visitor_id';
    public const PRODUCT_ID = 'product_id';
    public const WATCH_TYPE = 'watch_type';
    public const LAST_KNOWN_PRICE = 'last_known_price';
    public const LAST_KNOWN_IN_STOCK = 'last_known_in_stock';
    public const CREATED_AT = 'created_at';
    public const NOTIFIED_AT = 'notified_at';

    public const WATCH_TYPE_PRICE_DROP = 'price_drop';
    public const WATCH_TYPE_BACK_IN_STOCK = 'back_in_stock';

    protected function _construct(): void
    {
        $this->_init(PriceWatchSubscriptionResource::class);
    }

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

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): self
    {
        $this->setData(self::PRODUCT_ID, $productId);
        return $this;
    }

    public function getWatchType(): string
    {
        return (string) $this->getData(self::WATCH_TYPE);
    }

    public function setWatchType(string $watchType): self
    {
        $this->setData(self::WATCH_TYPE, $watchType);
        return $this;
    }

    public function getLastKnownPrice(): ?float
    {
        $value = $this->getData(self::LAST_KNOWN_PRICE);
        return $value === null ? null : (float) $value;
    }

    public function setLastKnownPrice(?float $lastKnownPrice): self
    {
        $this->setData(self::LAST_KNOWN_PRICE, $lastKnownPrice);
        return $this;
    }

    public function getLastKnownInStock(): ?bool
    {
        $value = $this->getData(self::LAST_KNOWN_IN_STOCK);
        return $value === null ? null : (bool) $value;
    }

    public function setLastKnownInStock(?bool $lastKnownInStock): self
    {
        $this->setData(self::LAST_KNOWN_IN_STOCK, $lastKnownInStock);
        return $this;
    }

    public function setCreatedAt(string $createdAt): self
    {
        $this->setData(self::CREATED_AT, $createdAt);
        return $this;
    }

    public function getNotifiedAt(): ?string
    {
        $value = $this->getData(self::NOTIFIED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setNotifiedAt(?string $notifiedAt): self
    {
        $this->setData(self::NOTIFIED_AT, $notifiedAt);
        return $this;
    }
}
