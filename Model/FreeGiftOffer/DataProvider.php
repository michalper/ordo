<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\FreeGiftOffer;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
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
    /**
     * @var array
     */
    protected $loadedData;

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

    public function getData(): array
    {
        if (isset($this->loadedData)) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $offer) {
            $offerData = $offer->getData();
            $offerId = (int) $offer->getEntityId();

            $tierCollection = $this->tierCollectionFactory->create();
            $tierCollection->addOfferFilter($offerId);
            $offerData['tiers'] = array_values(array_map(
                static fn ($tier) => [
                    'min_subtotal' => $tier->getMinSubtotal(),
                    'gift_slots' => $tier->getGiftSlots(),
                ],
                $tierCollection->getItems()
            ));

            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addOfferFilter($offerId);
            $offerData['products'] = array_values(array_map(
                static fn ($product) => ['sku' => $product->getSku()],
                $productCollection->getItems()
            ));

            $this->loadedData[$offerId] = $offerData;
        }

        $persisted = $this->dataPersistor->get('ordo_free_gift_offer');
        if ($persisted) {
            $offerId = $persisted['entity_id'] ?? null;
            if ($offerId) {
                $this->loadedData[$offerId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_free_gift_offer');
        }

        return $this->loadedData;
    }
}
