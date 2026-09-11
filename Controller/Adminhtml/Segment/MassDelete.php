<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;

/**
 * See Campaign\MassDelete's own docblock for the shared Filter/collection pattern - no cache tag
 * to flush here (segments have no equivalent of CampaignDispatcher's cached trigger lookup).
 * Deletes through SegmentResource directly, same as the single-segment Delete controller.
 */
class MassDelete extends AbstractSegmentAction implements HttpPostActionInterface
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
            // Condition rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->segmentResource->delete($segment);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 segment(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
