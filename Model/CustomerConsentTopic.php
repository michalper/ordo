<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic as CustomerConsentTopicResource;

/**
 * One customer's consent state for one (channel, topic) pair - the topic-level sibling of
 * CustomerConsent, see that class's own doc and docs/adr/0026 for why this is a separate table
 * rather than a column added to ordo_customer_consent. Plain data holder; Model\ConsentManager
 * is the actual read/write API.
 */
class CustomerConsentTopic extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CHANNEL = 'channel';
    public const TOPIC = 'topic';
    public const CONSENTED = 'consented';
    public const SOURCE = 'source';
    public const UPDATED_AT = 'updated_at';

    protected function _construct(): void
    {
        $this->_init(CustomerConsentTopicResource::class);
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

    public function getTopic(): string
    {
        return (string) $this->getData(self::TOPIC);
    }

    public function setTopic(string $topic): self
    {
        $this->setData(self::TOPIC, $topic);
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
}
