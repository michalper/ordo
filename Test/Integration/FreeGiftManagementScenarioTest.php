<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferInterfaceFactory;
use Ordo\Automation\Api\Data\FreeGiftOfferProductInterfaceFactory;
use Ordo\Automation\Api\Data\FreeGiftOfferTierInterfaceFactory;
use Ordo\Automation\Api\Data\FreeGiftSelectionInterfaceFactory;
use Ordo\Automation\Api\FreeGiftManagementInterface;
use Ordo\Automation\Api\FreeGiftOfferProductRepositoryInterface;
use Ordo\Automation\Api\FreeGiftOfferRepositoryInterface;
use Ordo\Automation\Api\FreeGiftOfferTierRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\CollectionFactory as QuoteGiftItemCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * The free-gift feature has no storefront UI of its own in this repo (see SCENARIOS.md §5 —
 * FreeGiftManagementInterface is a pure REST surface, meant for a headless/PWA storefront to
 * call), so unlike this module's other "real end-to-end" coverage there is nothing for MFTF to
 * click through. This is the real-DI, real-DB equivalent instead: a real quote, a real product,
 * real offer/tier/pool rows, exercised through the exact same FreeGiftManagementInterface a
 * real storefront request would hit — earning a slot by crossing a tier's min_subtotal,
 * selecting the gift (added to the quote at a real, persisted zero custom price, not just
 * asserted in memory), and then Observer\TrimExcessFreeGifts silently dropping it again once a
 * real cart mutation (removing the paid item) drops the subtotal back below the tier.
 *
 * XML_PATH_FREE_GIFT_ENABLED has no etc/config.xml default (off unless explicitly configured,
 * same as tracking/order_approval/lead_scoring — see mftf.yml's own config:set calls for those)
 * — written directly via ConfigWriter + ReinitableConfigInterface::reinit() here since this
 * suite runs as plain PHPUnit, not through the MFTF/annotation-driven test framework that would
 * otherwise offer @magentoConfigFixture.
 *
 * No transactional rollback (see magento-integration-test-lite, same as this module's other
 * Test/Integration suites) — every test tracks what it creates and deletes it in tearDown().
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/FreeGiftManagementScenarioTest.php
 */
class FreeGiftManagementScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private ?int $offerId = null;
    private ?string $giftSku = null;
    private ?string $paidSku = null;
    private ?int $cartId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);

        self::$objectManager->get(ConfigWriter::class)->save(
            'ordo_automation/free_gift/enabled',
            1,
            ScopeInterface::SCOPE_STORES,
            self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId()
        );
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        self::$objectManager->get(ConfigWriter::class)->delete(
            'ordo_automation/free_gift/enabled',
            ScopeInterface::SCOPE_STORES,
            self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId()
        );
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function tearDown(): void
    {
        if ($this->cartId !== null) {
            try {
                self::$objectManager->get(CartRepositoryInterface::class)->deleteById($this->cartId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        if ($this->offerId !== null) {
            try {
                self::$objectManager->get(FreeGiftOfferRepositoryInterface::class)->deleteById($this->offerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only — cascades to the offer's tiers/products (see
                // db_schema.xml's ON DELETE CASCADE foreign keys).
            }
        }
        foreach ([$this->giftSku, $this->paidSku] as $sku) {
            if ($sku === null) {
                continue;
            }
            try {
                self::$objectManager->get(ProductRepositoryInterface::class)->deleteById($sku);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
    }

    public function testGiftIsEarnedSelectedAtZeroCostAndTrimmedWhenSubtotalDrops(): void
    {
        $this->paidSku = 'ordo-freegift-paid-' . uniqid('', true);
        $this->giftSku = 'ordo-freegift-gift-' . uniqid('', true);
        $paidProduct = $this->createSimpleProduct($this->paidSku, 100.00);
        $this->createSimpleProduct($this->giftSku, 25.00);

        $this->offerId = $this->createOffer('Integration Test Free Gift Offer', 50.00, 1, [$this->giftSku]);

        $this->cartId = $this->createGuestCartWithProduct($paidProduct);

        $freeGiftManagement = self::$objectManager->get(FreeGiftManagementInterface::class);

        // Step 1: the paid product (100.00) clears the tier's 50.00 min_subtotal — a real quote
        // subtotal crossing a real tier, not a synthetic value handed straight to the eligibility
        // calculation.
        $eligibility = $freeGiftManagement->getEligibility($this->cartId);
        self::assertSame(1, $eligibility->getEarnedSlots());
        self::assertSame(0, $eligibility->getUsedSlots());
        self::assertContains($this->giftSku, $eligibility->getEligibleSkus());

        // Step 2: select the gift — persisted to the quote at a real zero custom price, not
        // just asserted against FreeGiftManagement's in-memory return value.
        $selection = self::$objectManager->get(FreeGiftSelectionInterfaceFactory::class)->create();
        $selection->setSkus([$this->giftSku]);
        $afterSelect = $freeGiftManagement->selectGifts($this->cartId, $selection);
        self::assertSame(1, $afterSelect->getUsedSlots());
        self::assertSame(0, $afterSelect->getRemainingSlots());

        $cartRepository = self::$objectManager->get(CartRepositoryInterface::class);
        $quote = $cartRepository->get($this->cartId);
        $giftItem = $this->findItemBySku($quote, $this->giftSku);
        self::assertNotNull($giftItem, 'The selected gift must actually be a line item on the real quote.');
        self::assertSame(0.0, (float) $giftItem->getCustomPrice());

        $giftItemRows = self::$objectManager->get(QuoteGiftItemCollectionFactory::class)->create()
            ->addQuoteFilter($this->cartId);
        self::assertSame(1, $giftItemRows->getSize(), 'The ordo_quote_gift_item marker row must exist too.');

        // Step 3: remove the paid item — subtotal drops back below the tier's 50.00 threshold,
        // so the gift is no longer earned. collectTotals() dispatches sales_quote_collect_totals_after
        // for real, which is what actually fires Observer\TrimExcessFreeGifts — no direct call
        // into the observer, exercising the real wiring end to end.
        $paidItem = $this->findItemBySku($quote, $this->paidSku);
        self::assertNotNull($paidItem);
        $quote->removeItem((int) $paidItem->getId());
        // Quote::collectTotals() no-ops if totals_collected_flag is already set (true ever
        // since the very first collectTotals() call in createGuestCartWithProduct()) —
        // removeItem() doesn't clear that flag itself, so without resetting it here the second
        // collectTotals() below would silently skip recomputation entirely, never dispatching
        // sales_quote_collect_totals_after and never firing Observer\TrimExcessFreeGifts.
        // Confirmed by actually hitting this exact no-op against a live instance — same reset a
        // real cart-update controller (e.g. Magento\Checkout\Model\Cart) performs before saving.
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $cartRepository->save($quote);

        $quoteAfterTrim = $cartRepository->get($this->cartId);
        self::assertNull(
            $this->findItemBySku($quoteAfterTrim, $this->giftSku),
            'TrimExcessFreeGifts must have removed the gift once the subtotal no longer earns it.'
        );

        $giftItemRowsAfterTrim = self::$objectManager->get(QuoteGiftItemCollectionFactory::class)->create()
            ->addQuoteFilter($this->cartId);
        self::assertSame(0, $giftItemRowsAfterTrim->getSize(), 'The stale marker row must be cleaned up too.');
    }

    private function createSimpleProduct(string $sku, float $price): \Magento\Catalog\Api\Data\ProductInterface
    {
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        /** @var \Magento\Catalog\Api\Data\ProductInterface $product */
        $product = self::$objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product->setSku($sku);
        $product->setName('Ordo Free Gift Test Product ' . $sku);
        $product->setPrice($price);
        $product->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $product->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
        $product->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        return self::$objectManager->get(ProductRepositoryInterface::class)->save($product);
    }

    /**
     * @param string[] $poolSkus
     */
    private function createOffer(string $name, float $minSubtotal, int $giftSlots, array $poolSkus): int
    {
        $offer = self::$objectManager->get(FreeGiftOfferInterfaceFactory::class)->create();
        $offer->setName($name);
        $offer->setEnabled(true);
        $offer = self::$objectManager->get(FreeGiftOfferRepositoryInterface::class)->save($offer);
        $offerId = (int) $offer->getEntityId();

        $tier = self::$objectManager->get(FreeGiftOfferTierInterfaceFactory::class)->create();
        $tier->setOfferId($offerId);
        $tier->setMinSubtotal($minSubtotal);
        $tier->setGiftSlots($giftSlots);
        self::$objectManager->get(FreeGiftOfferTierRepositoryInterface::class)->save($tier);

        foreach ($poolSkus as $sku) {
            $poolProduct = self::$objectManager->get(FreeGiftOfferProductInterfaceFactory::class)->create();
            $poolProduct->setOfferId($offerId);
            $poolProduct->setSku($sku);
            self::$objectManager->get(FreeGiftOfferProductRepositoryInterface::class)->save($poolProduct);
        }

        return $offerId;
    }

    private function createGuestCartWithProduct(\Magento\Catalog\Api\Data\ProductInterface $product): int
    {
        $cartManagement = self::$objectManager->get(CartManagementInterface::class);
        $cartId = (int) $cartManagement->createEmptyCart();

        $cartRepository = self::$objectManager->get(CartRepositoryInterface::class);
        $quote = $cartRepository->get($cartId);
        $quote->addProduct($product, 1);
        $quote->collectTotals();
        $cartRepository->save($quote);

        return $cartId;
    }

    private function findItemBySku(\Magento\Quote\Model\Quote $quote, string $sku): ?\Magento\Quote\Model\Quote\Item
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->getSku() === $sku) {
                return $item;
            }
        }
        return null;
    }
}
