<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\ContentBlockActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractContentBlockAction implements HttpPostActionInterface
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
