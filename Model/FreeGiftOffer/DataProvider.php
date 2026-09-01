<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\FreeGiftOffer;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\FreeGiftOfferProduct;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as FreeGiftOfferProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as FreeGiftOfferTierCollectionFactory;

/**
 * Feeds the free gift offer edit form, including the two dynamicRows sections (tiers,
 * products) — those aren't columns on ordo_free_gift_offer itself, so they're loaded
 * separately here and nested under the keys the form's dynamicRows fields expect (see
 * ordo_free_gift_offer_form.xml). Same pattern as Model\Campaign\DataProvider.
 */
class DataProvider extends AbstractDataProvider
{
    protected ?array $loadedData = null;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        FreeGiftOfferCollectionFactory $collectionFactory,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly FreeGiftOfferProductCollectionFactory $productCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $offer) {
            /** @var array<string, mixed> $offerData */
            $offerData = $offer->getData();
            $offerId = (int) $offer->getEntityId();

            $tierCollection = $this->tierCollectionFactory->create();
            $tierCollection->addOfferFilter($offerId);
            $offerData['tiers'] = array_values(array_map(
                static fn (FreeGiftOfferTier $tier) => [
                    'min_subtotal' => $tier->getMinSubtotal(),
                    'gift_slots' => $tier->getGiftSlots(),
                ],
                $tierCollection->getItems()
            ));

            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addOfferFilter($offerId);
            $offerData['products'] = array_values(array_map(
                static fn (FreeGiftOfferProduct $product) => ['sku' => $product->getSku()],
                $productCollection->getItems()
            ));

            $this->loadedData[$offerId] = $offerData;
        }

        /** @var array<string, mixed>|null $persisted */
        $persisted = $this->dataPersistor->get('ordo_free_gift_offer');
        if ($persisted) {
            $offerId = (int) ($persisted['entity_id'] ?? 0);
            if ($offerId) {
                $this->loadedData[$offerId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_free_gift_offer');
        }

        return $this->loadedData;
    }
}
