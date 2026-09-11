<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;

/**
 * One append-only row per Model\ConsentManager::setConsent() call - "was this customer opted in
 * for SMS on date X" is exactly what most real GDPR audits ask for, and ordo_customer_consent
 * itself only ever holds the CURRENT state (each row is upserted in place, so an earlier state is
 * simply gone the moment it changes). This table never updates a row once written, closing that
 * gap without touching ordo_customer_consent's own upsert behavior at all.
 */
class CustomerConsentLog extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CHANNEL = 'channel';
    public const CONSENTED = 'consented';
    public const SOURCE = 'source';
    public const CREATED_AT = 'created_at';

    protected function _construct(): void
    {
        $this->_init(CustomerConsentLogResource::class);
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

    public function getChannel(): string
    {
        return (string) $this->getData(self::CHANNEL);
    }

    public function setChannel(string $channel): self
    {
        $this->setData(self::CHANNEL, $channel);
        return $this;
    }

    public function isConsented(): bool
    {
        return (bool) $this->getData(self::CONSENTED);
    }

    public function setConsented(bool $consented): self
    {
        $this->setData(self::CONSENTED, $consented);
        return $this;
    }

    public function getSource(): ?string
    {
        $value = $this->getData(self::SOURCE);
        return $value === null ? null : (string) $value;
    }

    public function setSource(?string $source): self
    {
        $this->setData(self::SOURCE, $source);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string) $value;
    }
}
