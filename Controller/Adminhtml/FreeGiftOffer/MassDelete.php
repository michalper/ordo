<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;

/**
 * See MassEnable's own docblock for the shared Filter/collection pattern.
 */
class MassDelete extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly FreeGiftOfferCollectionFactory $offerCollectionFactory,
        private readonly FreeGiftOfferResource $offerResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->offerCollectionFactory->create());

        $count = 0;
        foreach ($collection as $offer) {
            /** @var \Ordo\Automation\Model\FreeGiftOffer $offer */
            // Tier/product rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->offerResource->delete($offer);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 free gift offer(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
