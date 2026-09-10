<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;

/**
 * Backs the "Estimated audience size" counter on the segment edit page
 * (view/adminhtml/web/js/segment-audience-size.js) - re-resolves the segment's already-*saved*
 * conditions via SegmentMemberResolver (the same resolver BulkAction uses to build its own
 * target list) and returns just the count. Deliberately GET-triggered by an explicit refresh
 * click rather than on every keystroke while editing conditions: SegmentMemberResolver runs one
 * or more real DB aggregate queries per condition, so wiring it to fire on unsaved, in-progress
 * edits (which it structurally can't see anyway - conditions only exist in the DB once saved)
 * would be both meaningless and slow at scale.
 */
class AudienceSize extends AbstractSegmentAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SegmentMemberResolver $segmentMemberResolver
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $segmentId = (int) $this->getRequest()->getParam('segment_id');
        $result = $this->resultJsonFactory->create();

        if ($segmentId <= 0) {
            return $result->setData(['count' => 0]);
        }

        $count = count($this->segmentMemberResolver->getMatchingCustomerIds($segmentId));

        return $result->setData(['count' => $count]);
    }
}
