<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;

class SegmentCondition extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(SegmentConditionResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getSegmentId(): int
    {
        return (int) $this->getData('segment_id');
    }

    public function setSegmentId(int $segmentId): self
    {
        $this->setData('segment_id', $segmentId);
        return $this;
    }

    public function getType(): string
    {
        return (string) $this->getData('type');
    }

    public function setType(string $type): self
    {
        $this->setData('type', $type);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        $raw = (string) $this->getData('params');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    public function getParamsJson(): string
    {
        return (string) $this->getData('params');
    }

    public function setParamsJson(string $paramsJson): self
    {
        $this->setData('params', $paramsJson);
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
