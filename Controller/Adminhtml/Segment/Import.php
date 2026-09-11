<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\Import\UploadedJsonFileReader;
use Ordo\Automation\Model\Segment\SegmentImporter;

/**
 * Handles ImportForm.php's file upload - see Controller\Adminhtml\Campaign\Import's own docblock
 * for the shared reasoning (same pattern, one per entity). Model\Segment\SegmentImporter does the
 * actual validation/persistence.
 */
class Import extends AbstractSegmentAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly SegmentImporter $segmentImporter,
        private readonly UploadedJsonFileReader $uploadedJsonFileReader
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $decoded = $this->uploadedJsonFileReader->read($this->getRequest(), 'import_file');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            return $resultRedirect->setPath('*/*/importform');
        }

        try {
            $segment = $this->segmentImporter->import($decoded);
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            return $resultRedirect->setPath('*/*/importform');
        }

        $this->messageManager->addSuccessMessage(__('Segment "%1" was imported.', $segment->getName()));
        return $resultRedirect->setPath('*/*/edit', ['entity_id' => $segment->getEntityId()]);
    }
}
