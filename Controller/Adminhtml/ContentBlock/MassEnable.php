<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;

/**
 * Grid mass-action counterpart to the single-block enable/disable toggle already available from
 * Content Block Edit — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassEnable, adapted to
 * this entity's plain Factory + ResourceModel::save() (ContentBlock has no repository interface).
 */
class MassEnable extends AbstractContentBlockAction implements HttpPostActionInterface
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
            $contentBlock->setEnabled(true);
            $this->contentBlockResource->save($contentBlock);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 content block(s) have been enabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
