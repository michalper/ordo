<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\FreeGiftOfferProductFactory;
use Ordo\Automation\Model\FreeGiftOfferTierFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as FreeGiftOfferProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as FreeGiftOfferTierCollectionFactory;

/**
 * Persists the offer row plus its tier/product child rows in one request, same
 * delete-then-reinsert pattern as Controller\Adminhtml\Campaign\Save — simple and correct for
 * the small row counts a free gift offer realistically has.
 */
class Save extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferResource $offerResource,
        private readonly FreeGiftOfferTierFactory $tierFactory,
        private readonly FreeGiftOfferTierResource $tierResource,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly FreeGiftOfferProductFactory $productFactory,
        private readonly FreeGiftOfferProductResource $productResource,
        private readonly FreeGiftOfferProductCollectionFactory $productCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var array<string, mixed> $data */
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);
        $offer = $this->offerFactory->create();

        if ($entityId) {
            $this->offerResource->load($offer, $entityId);
        }

        $offer->setName((string) ($data['name'] ?? ''));
        $offer->setEnabled(!empty($data['enabled']));

        /** @var array<int, array<string, mixed>> $tierRows */
        $tierRows = (array) ($data['tiers']['tiers'] ?? []);
        /** @var array<int, array<string, mixed>> $productRows */
        $productRows = (array) ($data['products']['products'] ?? []);

        try {
            $this->offerResource->save($offer);
            $this->saveChildRows(
                (int) $offer->getEntityId(),
                $tierRows,
                $productRows
            );

            $this->messageManager->addSuccessMessage(__('The free gift offer has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $offer->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the free gift offer: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $tierRows
     * @param array<int, array<string, mixed>> $productRows
     */
    private function saveChildRows(int $offerId, array $tierRows, array $productRows): void
    {
        $tiers = $this->tierCollectionFactory->create();
        $tiers->addOfferFilter($offerId);
        foreach ($tiers as $existing) {
            $this->tierResource->delete($existing);
        }

        foreach ($tierRows as $row) {
            if (!isset($row['min_subtotal'], $row['gift_slots']) || $row['min_subtotal'] === '') {
                continue;
            }

            $tier = $this->tierFactory->create();
            $tier->setData([
                'offer_id' => $offerId,
                'min_subtotal' => (float) $row['min_subtotal'],
                'gift_slots' => (int) $row['gift_slots'],
            ]);
            $this->tierResource->save($tier);
        }

        $products = $this->productCollectionFactory->create();
        $products->addOfferFilter($offerId);
        foreach ($products as $existing) {
            $this->productResource->delete($existing);
        }

        foreach ($productRows as $row) {
            if (empty($row['sku'])) {
                continue;
            }

            $product = $this->productFactory->create();
            $product->setData([
                'offer_id' => $offerId,
                'sku' => (string) $row['sku'],
            ]);
            $this->productResource->save($product);
        }
    }
}
