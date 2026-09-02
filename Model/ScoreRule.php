<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;

/**
 * A declarative demographic-attribute lead scoring rule — see etc/db_schema.xml's
 * ordo_score_rule comment. Admin-only, plain AbstractModel, same shape as Segment.
 */
class ScoreRule extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ScoreRuleResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getAttributeCode(): string
    {
        return (string) $this->getData('attribute_code');
    }

    public function setAttributeCode(string $attributeCode): self
    {
        $this->setData('attribute_code', $attributeCode);
        return $this;
    }

    public function getOperator(): string
    {
        return (string) $this->getData('operator');
    }

    public function setOperator(string $operator): self
    {
        $this->setData('operator', $operator);
        return $this;
    }

    public function getValue(): string
    {
        return (string) $this->getData('value');
    }

    public function setValue(string $value): self
    {
        $this->setData('value', $value);
        return $this;
    }

    public function getPoints(): int
    {
        return (int) $this->getData('points');
    }

    public function setPoints(int $points): self
    {
        $this->setData('points', $points);
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData('enabled');
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData('enabled', $enabled);
        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->setData('sort_order', $sortOrder);
        return $this;
    }
}
