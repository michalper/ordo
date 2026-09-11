<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;

/**
 * See MassEnable's own docblock - same pattern, opposite direction.
 */
class MassDisable extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
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
            $offer->setEnabled(false);
            $this->offerResource->save($offer);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 free gift offer(s) have been disabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
