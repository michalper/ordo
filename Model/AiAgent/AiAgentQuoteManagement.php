<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\AiAgentQuoteManagementInterface;
use Ordo\Automation\Api\Data\AiAgentQuoteResultInterface;
use Ordo\Automation\Helper\Config;

/**
 * Builds a throwaway (never persisted) Magento\Quote\Model\Quote for exactly this request's
 * items/address, runs it through Magento's own price/discount/tax/shipping engines via
 * collectTotals(), and reads the totals back into one DTO - a price check, not a cart. No
 * Model\FreeGiftManagement-style CartRepositoryInterface::get()/save() here on purpose: every
 * other quote-touching service in this module operates on a real, persisted, customer-owned
 * cart; this is the first that deliberately never saves one, since a saved quote per AI-agent
 * price check would leave orphan rows behind for every lookup.
 */
class AiAgentQuoteManagement implements AiAgentQuoteManagementInterface
{
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AiAgentQuoteLineFactory $lineFactory,
        private readonly AiAgentQuoteResultFactory $resultFactory,
        private readonly Config $config
    ) {
    }

    public function getQuote(
        array $items,
        string $countryId,
        string $postcode,
        ?string $region = null
    ): AiAgentQuoteResultInterface {
        /** @var \Magento\Store\Model\Store $store getCurrentCurrencyCode() isn't declared on
         *  StoreInterface, only the concrete Store model - same real-world usage as
         *  GoogleMerchantFeedGenerator/AiAgentFeedGenerator. */
        $store = $this->storeManager->getStore();

        /** @var Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);

        /** @var array<int, array{sku: string, qty: float, item: QuoteItem}> $addedItems */
        $addedItems = [];
        $unmatchedSkus = [];

        foreach ($items as $item) {
            $quoteItem = $this->addItem($quote, $item->getSku(), $item->getQty());
            if ($quoteItem instanceof QuoteItem) {
                $addedItems[] = ['sku' => $item->getSku(), 'qty' => $item->getQty(), 'item' => $quoteItem];
            } else {
                $unmatchedSkus[] = $item->getSku();
            }
        }

        if ($addedItems === []) {
            throw new InputException(__('None of the requested SKUs could be quoted.'));
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCountryId($countryId);
        $shippingAddress->setPostcode($postcode);
        if ($region !== null) {
            $shippingAddress->setRegion($region);
        }
        $shippingAddress->setCollectShippingRates(true);

        $quote->collectTotals();
        $shippingAddress->collectShippingRates();

        $cheapestRate = null;
        foreach ($shippingAddress->getAllShippingRates() as $rate) {
            if ($cheapestRate === null || (float) $rate->getPrice() < (float) $cheapestRate->getPrice()) {
                $cheapestRate = $rate;
            }
        }
        if ($cheapestRate !== null) {
            $shippingAddress->setShippingMethod((string) $cheapestRate->getCode());
        }

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        $lines = [];
        foreach ($addedItems as $addedItem) {
            $lines[] = $this->lineFactory->create()
                ->setSku($addedItem['sku'])
                ->setQty($addedItem['qty'])
                ->setUnitPrice((float) $addedItem['item']->getPrice())
                ->setRowTotal((float) $addedItem['item']->getRowTotal());
        }

        return $this->resultFactory->create()
            // Same "read the store's own current currency code" approach as
            // GoogleMerchantFeedGenerator/AiAgentFeedGenerator - this quote is never persisted
            // through the normal checkout session flow that would otherwise populate
            // Quote::getQuoteCurrencyCode() itself.
            ->setCurrency((string) $store->getCurrentCurrencyCode())
            ->setSubtotal((float) $shippingAddress->getSubtotal())
            ->setDiscountAmount(abs((float) $shippingAddress->getDiscountAmount()))
            ->setShippingAmount((float) $shippingAddress->getShippingAmount())
            ->setGrandTotal((float) $shippingAddress->getGrandTotal())
            ->setEstimatedDeliveryDays($this->config->getAiAgentEstimatedDeliveryDays((int) $store->getId()))
            ->setLines($lines)
            ->setUnmatchedSkus($unmatchedSkus);
    }

    private function addItem(Quote $quote, string $sku, float $qty): ?QuoteItem
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            return null;
        }

        try {
            $item = $quote->addProduct($product, $qty);
        } catch (LocalizedException) {
            return null;
        }

        if (is_string($item)) {
            // Quote::addProduct() returns a string message instead of throwing when the
            // product can't be added (e.g. out of stock) - same "not an exception" quirk
            // Model\FreeGiftManagement's own addProduct() caller has to account for.
            return null;
        }

        // Deliberately NOT read into an AiAgentQuoteLine here - getPrice() is set immediately,
        // but getRowTotal() (and any per-item discount) is only populated once collectTotals()
        // actually runs the totals-collection pipeline, which hasn't happened yet at this point
        // in the call sequence. Reading it here would silently return 0 for every row_total.
        return $item;
    }
}
