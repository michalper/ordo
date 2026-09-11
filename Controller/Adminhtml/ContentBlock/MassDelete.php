<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;

/**
 * See MassEnable's own docblock for the shared Filter/collection pattern.
 */
class MassDelete extends AbstractContentBlockAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly ContentBlockCollectionFactory $contentBlockCollectionFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->contentBlockCollectionFactory->create());

        $count = 0;
        foreach ($collection as $contentBlock) {
            /** @var \Ordo\Automation\Model\ContentBlock $contentBlock */
            $this->contentBlockResource->delete($contentBlock);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 content block(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
