<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * Invoked via a plain GET link (see Ui\Component\Listing\Column\ContentBlockActions).
 */
class Delete extends AbstractContentBlockAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing content block id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $contentBlock = $this->contentBlockFactory->create();
            $this->contentBlockResource->load($contentBlock, $entityId);
            $this->contentBlockResource->delete($contentBlock);

            $this->messageManager->addSuccessMessage(__('The content block has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the content block: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
