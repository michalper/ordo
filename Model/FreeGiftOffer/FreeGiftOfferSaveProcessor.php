<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\FreeGiftOffer;

use Ordo\Automation\Model\FreeGiftOffer;
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
 * delete-then-reinsert pattern as Model\Campaign\CampaignSaveProcessor — simple and correct for
 * the small row counts a free gift offer realistically has.
 *
 * Extracted from Controller\Adminhtml\FreeGiftOffer\Save so the persistence logic can be unit
 * tested and reused without a controller in the loop; the controller still owns the
 * HTTP/session/redirect concerns.
 */
class FreeGiftOfferSaveProcessor
{
    public function __construct(
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferResource $offerResource,
        private readonly FreeGiftOfferTierFactory $tierFactory,
        private readonly FreeGiftOfferTierResource $tierResource,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly FreeGiftOfferProductFactory $productFactory,
        private readonly FreeGiftOfferProductResource $productResource,
        private readonly FreeGiftOfferProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Loads the offer (when an entity_id was posted), applies the posted fields, saves it, and
     * rebuilds its tier/product child rows. Returns the saved offer so the caller can read back
     * the entity_id for redirects/messages.
     *
     * @param array<string, mixed> $data
     */
    public function process(array $data): FreeGiftOffer
    {
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

        $this->offerResource->save($offer);
        $this->saveChildRows(
            (int) $offer->getEntityId(),
            $tierRows,
            $productRows
        );

        return $offer;
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
