<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;

/**
 * Backs the Segment Overlap page's (Controller\Adminhtml\Segment\Overlap) "Compare" button - runs
 * SegmentMemberResolver::getMatchingCustomerIds() once per picked segment (same call
 * Controller\Adminhtml\Segment\AudienceSize already makes for a single segment) and returns the
 * two segments' sizes plus their intersection/unique-remainder counts, computed with
 * array_intersect()/array_diff() rather than any new resolver logic.
 */
class OverlapCompute extends AbstractSegmentAction implements HttpGetActionInterface
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
        $result = $this->resultJsonFactory->create();

        $segmentIdA = (int) $this->getRequest()->getParam('segment_id_a');
        $segmentIdB = (int) $this->getRequest()->getParam('segment_id_b');

        if ($segmentIdA <= 0 || $segmentIdB <= 0 || $segmentIdA === $segmentIdB) {
            return $result->setData(['error' => (string) __('Pick two different segments.')]);
        }

        $customerIdsA = $this->segmentMemberResolver->getMatchingCustomerIds($segmentIdA);
        $customerIdsB = $this->segmentMemberResolver->getMatchingCustomerIds($segmentIdB);

        $intersection = array_intersect($customerIdsA, $customerIdsB);

        return $result->setData([
            'size_a' => count($customerIdsA),
            'size_b' => count($customerIdsB),
            'intersection' => count($intersection),
            'unique_a' => count(array_diff($customerIdsA, $customerIdsB)),
            'unique_b' => count(array_diff($customerIdsB, $customerIdsA)),
        ]);
    }
}
