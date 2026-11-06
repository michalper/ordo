<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

/**
 * A declarative lead-routing rule — see etc/db_schema.xml's ordo_lead_routing_rule comment.
 * Admin-only, a named, toggleable top-level entity like Segment.
 */
class LeadRoutingRule extends AbstractNamedToggleableEntityModel
{
    protected function _construct(): void
    {
        $this->_init(LeadRoutingRuleResource::class);
    }

    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
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

    /**
     * JSON-encoded array of {"email", "name", "phone"} - see LeadAssigner for the decoded shape.
     */
    public function getReps(): string
    {
        return (string) $this->getData('reps');
    }

    public function setReps(string $reps): self
    {
        $this->setData('reps', $reps);
        return $this;
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
