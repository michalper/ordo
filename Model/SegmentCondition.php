<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;

class SegmentCondition extends AbstractModel
{
    use ChildRowGettersTrait;

    protected function _construct(): void
    {
        $this->_init(SegmentConditionResource::class);
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

    public function setType(string $type): self
    {
        $this->setData('type', $type);
        return $this;
    }

    public function setParamsJson(string $paramsJson): self
    {
        $this->setData('params', $paramsJson);
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->setData('sort_order', $sortOrder);
        return $this;
    }
}
