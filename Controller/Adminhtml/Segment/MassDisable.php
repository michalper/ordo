<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;

/**
 * See MassEnable's own docblock - same pattern, opposite direction.
 */
class MassDisable extends AbstractSegmentAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->segmentCollectionFactory->create());

        $count = 0;
        foreach ($collection as $segment) {
            /** @var \Ordo\Automation\Model\Segment $segment */
            $segment->setEnabled(false);
            $this->segmentResource->save($segment);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 segment(s) have been disabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
