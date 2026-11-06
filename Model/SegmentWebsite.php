<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Segment\Website as SegmentWebsiteResource;

/**
 * Admin-only, same as SegmentCondition - no Api\Data\SegmentWebsiteInterface (see Segment's own
 * docblock for why nothing under Segment gets a service-contract interface).
 */
class SegmentWebsite extends AbstractModel
{
    use HasEntityIdGetterTrait;

    protected function _construct(): void
    {
        $this->_init(SegmentWebsiteResource::class);
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

    public function getWebsiteId(): int
    {
        return (int) $this->getData('website_id');
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData('website_id', $websiteId);
        return $this;
    }
}
