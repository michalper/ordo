<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as FreeGiftOfferTierCollectionFactory;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\CollectionFactory as QuoteGiftItemCollectionFactory;

/**
 * Fires after every quote totals recalculation. If the cart subtotal has dropped below the
 * threshold(s) that earned the customer's currently-selected free gifts (e.g. they removed a
 * paid item), the excess gifts are silently dropped from the cart — the customer keeps whatever
 * still fits their earned slots, from the tail of their selection.
 *
 * Deliberately does NOT call $quote->collectTotals() itself, to avoid re-entering this same
 * event — the caller's own totals collection already reflects the current cart state.
 */
class TrimExcessFreeGifts implements ObserverInterface
{
    public function __construct(
        private readonly FreeGiftOfferCollectionFactory $offerCollectionFactory,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly QuoteGiftItemCollectionFactory $giftItemCollectionFactory,
        private readonly QuoteGiftItemResource $giftItemResource,
        private readonly Config $config
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote->getId()) {
            return;
        }

        $giftRows = $this->giftItemCollectionFactory->create()->addQuoteFilter((int) $quote->getId());
        if ($giftRows->getSize() === 0) {
            return;
        }

        $earned = $this->config->isFreeGiftEnabled((int) $quote->getStoreId())
            ? $this->earnedSlots($this->currentSubtotal($quote))
            : 0;
        $excess = $giftRows->getSize() - $earned;
        if ($excess <= 0) {
            return;
        }

        $rows = array_values($giftRows->getItems());
        for ($i = 0; $i < $excess; $i++) {
            $row = $rows[count($rows) - 1 - $i];
            try {
                $quote->removeItem((int) $row->getQuoteItemId());
            } catch (\Exception $e) {
                // Already gone from the quote — still clean up the stale marker row below.
            }
            $this->giftItemResource->delete($row);
        }
    }

    /**
     * $quote->getSubtotal() is stale here: this observer fires from inside
     * Quote\TotalsCollector::collect(), BEFORE Quote::collectTotals() applies the just-computed
     * totals back onto the quote's own data (Quote::collectTotals() calls
     * $this->addData($total->getData()) only after totalsCollector->collect($this) — which is
     * what dispatches this very event — returns). Confirmed via a real integration-test run:
     * reading $quote->getSubtotal() here still returned the PREVIOUS collectTotals() result, one
     * cart mutation behind. Address\Total\Subtotal::collect() does set the fresh value on each
     * address right away, though (Quote\TotalsCollector::collect() sums exactly this to build
     * its own $total), so summing that instead is accurate at the moment this observer runs.
     */
    private function currentSubtotal(Quote $quote): float
    {
        $subtotal = 0.0;
        foreach ($quote->getAllAddresses() as $address) {
            $subtotal += (float) $address->getSubtotal();
        }
        return $subtotal;
    }

    private function earnedSlots(float $subtotal): int
    {
        $offerIds = array_map(
            'intval',
            $this->offerCollectionFactory->create()->addEnabledFilter()->getAllIds()
        );
        if (!$offerIds) {
            return 0;
        }

        $earned = 0;
        foreach ($this->tierCollectionFactory->create()->addOffersFilter($offerIds) as $tier) {
            if ($tier->getMinSubtotal() <= $subtotal) {
                $earned += $tier->getGiftSlots();
            }
        }

        return $earned;
    }
}
