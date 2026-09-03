<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

class Edit extends AbstractContentBlockAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $contentBlock = $this->contentBlockFactory->create();

        if ($entityId) {
            $this->contentBlockResource->load($contentBlock, $entityId);
            if (!$contentBlock->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This content block no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $this->registry->register('ordo_content_block', $contentBlock);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId ? __('Edit Content Block "%1"', $contentBlock->getName()) : __('New Content Block')
        );

        return $resultPage;
    }
}
