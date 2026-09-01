<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\SegmentFactory;

/**
 * Invoked via a plain GET link (see Ui\Component\Listing\Column\SegmentActions).
 */
class Delete extends AbstractSegmentAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing segment id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $segment = $this->segmentFactory->create();
            $this->segmentResource->load($segment, $entityId);
            // Condition rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->segmentResource->delete($segment);

            $this->messageManager->addSuccessMessage(__('The segment has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the segment: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
