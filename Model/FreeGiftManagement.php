<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Api\Data\FreeGiftEligibilityInterface;
use Ordo\Automation\Api\Data\FreeGiftSelectionInterface;
use Ordo\Automation\Api\FreeGiftManagementInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as FreeGiftOfferProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as FreeGiftOfferTierCollectionFactory;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\CollectionFactory as QuoteGiftItemCollectionFactory;

class FreeGiftManagement implements FreeGiftManagementInterface
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly FreeGiftOfferCollectionFactory $offerCollectionFactory,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly FreeGiftOfferProductCollectionFactory $productCollectionFactory,
        private readonly QuoteGiftItemCollectionFactory $giftItemCollectionFactory,
        private readonly QuoteGiftItemFactory $giftItemFactory,
        private readonly QuoteGiftItemResource $giftItemResource,
        private readonly FreeGiftEligibilityFactory $eligibilityFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly UserContextInterface $userContext,
        private readonly Config $config
    ) {
    }

    public function getEligibility(int $cartId): FreeGiftEligibilityInterface
    {
        $quote = $this->cartRepository->get($cartId);
        $this->assertOwnership($quote);
        return $this->computeEligibility($quote);
    }

    public function selectGifts(int $cartId, FreeGiftSelectionInterface $selection): FreeGiftEligibilityInterface
    {
        $skus = array_values($selection->getSkus());
        if (count($skus) !== count(array_unique($skus))) {
            throw new InputException(__('The gift selection contains duplicate SKUs.'));
        }

        $quote = $this->cartRepository->get($cartId);
        $this->assertOwnership($quote);
        $eligibility = $this->computeEligibility($quote);

        if (count($skus) > $eligibility->getEarnedSlots()) {
            throw new LocalizedException(
                __('You may select at most %1 gift(s) with the current cart subtotal.', $eligibility->getEarnedSlots())
            );
        }

        $skuToOfferId = $this->mapEligibleSkusToOfferId($this->activeOfferIds($quote));
        foreach ($skus as $sku) {
            if (!isset($skuToOfferId[$sku])) {
                throw new InputException(__('SKU "%1" is not an eligible free gift for this cart.', $sku));
            }
        }

        $this->removeExistingGiftItems($quote);

        $addedItems = [];
        foreach ($skus as $sku) {
            $addedItems[$sku] = $this->addGiftItem($quote, $sku);
        }

        $quote->collectTotals();
        $this->cartRepository->save($quote);

        // Quote items only get a real item_id once the quote itself has been persisted — the
        // objects added above are the same in-memory instances Magento's save path populates,
        // so their id is only trustworthy to read *after* save(), not right after addProduct().
        foreach ($addedItems as $sku => $item) {
            $giftItem = $this->giftItemFactory->create();
            $giftItem->setQuoteId((int) $quote->getId());
            $giftItem->setQuoteItemId((int) $item->getId());
            $giftItem->setOfferId($skuToOfferId[$sku]);
            $giftItem->setSku($sku);
            $this->giftItemResource->save($giftItem);
        }

        return $this->computeEligibility($quote);
    }

    /**
     * A logged-in customer may only act on their own cart — a guest quote (customer_id null)
     * is left unchecked here since guest checkout has no identity to compare against; the
     * cart id itself (a large auto-increment, not guessable in practice for this module's
     * scope) is the only protection for guest carts, matching this module's other
     * customer-facing endpoints (see OfferManagement::selfExtend for the identical pattern).
     */
    private function assertOwnership(Quote $quote): void
    {
        $customerId = $this->userContext->getUserId();
        if ($customerId !== null
            && (int) $quote->getCustomerId() > 0
            && (int) $quote->getCustomerId() !== (int) $customerId
        ) {
            throw new NoSuchEntityException(__('Cart with id "%1" does not exist.', $quote->getId()));
        }
    }

    private function addGiftItem(Quote $quote, string $sku): \Magento\Quote\Model\Quote\Item
    {
        $product = $this->productRepository->get($sku);
        $result = $quote->addProduct($product, 1);

        if (is_string($result)) {
            throw new LocalizedException(__('Could not add gift "%1" to the cart: %2', $sku, $result));
        }

        $result->setCustomPrice(0.0);
        $result->setOriginalCustomPrice(0.0);
        $result->getProduct()->setIsSuperMode(true);

        return $result;
    }

    private function removeExistingGiftItems(Quote $quote): void
    {
        $rows = $this->giftItemCollectionFactory->create()->addQuoteFilter((int) $quote->getId());
        foreach ($rows as $row) {
            try {
                $quote->removeItem((int) $row->getQuoteItemId());
            } catch (\Exception $e) {
                // Item already gone from the quote (e.g. removed by the customer directly) —
                // the marker row is still stale and must be cleaned up below regardless.
            }
            $this->giftItemResource->delete($row);
        }
    }

    /**
     * @return int[]
     */
    private function activeOfferIds(Quote $quote): array
    {
        if (!$this->config->isFreeGiftEnabled((int) $quote->getStoreId())) {
            return [];
        }

        $subtotal = (float) $quote->getSubtotal();
        $offers = $this->offerCollectionFactory->create()->addEnabledFilter();
        $offerIds = array_map('intval', $offers->getAllIds());
        if (!$offerIds) {
            return [];
        }

        $tiersByOffer = [];
        foreach ($this->tierCollectionFactory->create()->addOffersFilter($offerIds) as $tier) {
            $tiersByOffer[$tier->getOfferId()][] = $tier;
        }

        $activeOfferIds = [];
        foreach ($tiersByOffer as $offerId => $tiers) {
            if ($this->earnedSlotsForOffer($tiers, $subtotal) > 0) {
                $activeOfferIds[] = (int) $offerId;
            }
        }

        return $activeOfferIds;
    }

    /**
     * @param \Ordo\Automation\Model\FreeGiftOfferTier[] $tiers
     */
    private function earnedSlotsForOffer(array $tiers, float $subtotal): int
    {
        $earned = 0;
        foreach ($tiers as $tier) {
            if ($tier->getMinSubtotal() <= $subtotal) {
                $earned += $tier->getGiftSlots();
            }
        }
        return $earned;
    }

    /**
     * @param int[] $activeOfferIds
     * @return array<string, int> sku => offer_id, first offer whose pool contains the sku wins
     */
    private function mapEligibleSkusToOfferId(array $activeOfferIds): array
    {
        if (!$activeOfferIds) {
            return [];
        }

        $map = [];
        foreach ($this->productCollectionFactory->create()->addOffersFilter($activeOfferIds) as $product) {
            $sku = $product->getSku();
            if (!isset($map[$sku])) {
                $map[$sku] = $product->getOfferId();
            }
        }

        return $map;
    }

    private function computeEligibility(Quote $quote): FreeGiftEligibilityInterface
    {
        $quote->collectTotals();

        $activeOfferIds = $this->activeOfferIds($quote);
        $earned = 0;
        if ($activeOfferIds) {
            $subtotal = (float) $quote->getSubtotal();
            foreach ($this->tierCollectionFactory->create()->addOffersFilter($activeOfferIds) as $tier) {
                if ($tier->getMinSubtotal() <= $subtotal) {
                    $earned += $tier->getGiftSlots();
                }
            }
        }

        $eligibleSkus = array_keys($this->mapEligibleSkusToOfferId($activeOfferIds));
        $used = $this->giftItemCollectionFactory->create()->addQuoteFilter((int) $quote->getId())->getSize();
        $remaining = max(0, $earned - $used);

        return $this->eligibilityFactory->create()
            ->setEarnedSlots($earned)
            ->setUsedSlots($used)
            ->setRemainingSlots($remaining)
            ->setEligibleSkus($eligibleSkus);
    }
}
