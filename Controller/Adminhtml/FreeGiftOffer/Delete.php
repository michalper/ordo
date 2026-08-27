<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

/**
 * Invoked via a plain GET link (see Ui\Component\Listing\Column\FreeGiftOfferActions).
 */
class Delete extends AbstractFreeGiftOfferAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferResource $offerResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing free gift offer id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $offer = $this->offerFactory->create();
            $this->offerResource->load($offer, $entityId);
            // Tier/product rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $this->offerResource->delete($offer);

            $this->messageManager->addSuccessMessage(__('The free gift offer has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the free gift offer: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
