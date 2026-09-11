<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;

/**
 * Grid mass-action counterpart to the single-offer enable/disable toggle already available from
 * Free Gift Offer Edit — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassEnable, adapted to
 * this entity's plain Factory + ResourceModel::save() (FreeGiftOffer has no repository interface).
 */
class MassEnable extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
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
            $offer->setEnabled(true);
            $this->offerResource->save($offer);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 free gift offer(s) have been enabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
