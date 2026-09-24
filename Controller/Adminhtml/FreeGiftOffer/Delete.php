<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\FreeGiftOfferActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferResource $offerResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $offer = $this->offerFactory->create();
        $this->offerResource->load($offer, $entityId);
        // Tier/product rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
        $this->offerResource->delete($offer);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing free gift offer id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The free gift offer has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the free gift offer: %1', $e->getMessage());
    }
}
