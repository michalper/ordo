<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Covers the Phase 3 free-gift feature's REST surface — the one gap left in Test/Api despite
 * README claiming this was "live-verified" (it was, manually, but never captured as an
 * automated test). No admin/frontend UI exists for the customer-facing half of this (see
 * ROADMAP.md's "Offer self-extension"-adjacent gaps), so this is REST-only, same as the rest
 * of this directory.
 */
class FreeGiftApiTest extends AbstractApiTestCase
{
    private function testSku(): string
    {
        $sku = getenv('ORDO_API_TEST_PRODUCT_SKU');
        if (!$sku) {
            self::markTestSkipped('ORDO_API_TEST_PRODUCT_SKU not set — see Test/Api/README.md.');
        }

        return $sku;
    }

    public function testOfferTierProductCrudCascadesOnDelete(): void
    {
        [$status, $offer] = $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offers', [
            'offer' => ['name' => 'API Test Offer', 'enabled' => true],
        ]);
        self::assertSame(200, $status, json_encode($offer));
        $offerId = $offer['entity_id'];

        try {
            [$status, $tier] = $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offer-tiers', [
                'tier' => ['offer_id' => $offerId, 'min_subtotal' => 1, 'gift_slots' => 1],
            ]);
            self::assertSame(200, $status, json_encode($tier));
            $tierId = $tier['entity_id'];

            [$status, $product] = $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offer-products', [
                'product' => ['offer_id' => $offerId, 'sku' => $this->testSku()],
            ]);
            self::assertSame(200, $status, json_encode($product));
            $productId = $product['entity_id'];

            [$status] = $this->asAdmin('DELETE', "/rest/V1/ordo/free-gift-offers/{$offerId}");
            self::assertSame(200, $status);
            $offerId = null; // already deleted — the finally block below must not double-delete.

            // Deleting the offer cascades to its tiers/products at the DB level (FK constraint)
            // — neither needs (or gets) an explicit delete call of its own.
            [$status] = $this->asAdmin('GET', "/rest/V1/ordo/free-gift-offer-tiers/{$tierId}");
            self::assertSame(404, $status);
            [$status] = $this->asAdmin('GET', "/rest/V1/ordo/free-gift-offer-products/{$productId}");
            self::assertSame(404, $status);
        } finally {
            // A prior version of this test had no such guard: an assertion failure partway
            // through left the offer (and its tiers/products) permanently active, inflating
            // `earned_slots` for every later run of testEligibilityAndSelectionOnARealCart
            // against the same persistent ORDO_API_CUSTOMER_EMAIL customer — found and fixed by
            // actually running this against a real instance twice in a row, not assumed.
            if ($offerId !== null) {
                $this->asAdmin('DELETE', "/rest/V1/ordo/free-gift-offers/{$offerId}");
            }
        }
    }

    /**
     * Requires ORDO_API_TEST_PRODUCT_SKU: a real, existing, purchasable product SKU, since
     * selectGifts() calls through to Quote::addProduct() for real — there's no fixture loader
     * available to this portable test client (see Test/Api/README.md).
     */
    public function testEligibilityAndSelectionOnARealCart(): void
    {
        $sku = $this->testSku();

        [$status, $offer] = $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offers', [
            'offer' => ['name' => 'API Test Eligibility Offer', 'enabled' => true],
        ]);
        self::assertSame(200, $status);
        $offerId = $offer['entity_id'];

        try {
            $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offer-tiers', [
                'tier' => ['offer_id' => $offerId, 'min_subtotal' => 1, 'gift_slots' => 1],
            ]);
            $this->asAdmin('POST', '/rest/V1/ordo/free-gift-offer-products', [
                'product' => ['offer_id' => $offerId, 'sku' => $sku],
            ]);

            [$status, $cartId] = $this->asCustomer('POST', '/rest/V1/carts/mine');
            self::assertSame(200, $status, json_encode($cartId));

            [$status, $item] = $this->asCustomer('POST', '/rest/V1/carts/mine/items', [
                'cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => (string) $cartId],
            ]);
            self::assertSame(200, $status, json_encode($item));

            // selectGifts() REPLACES the cart's selection each call — start from a known-empty
            // state rather than assuming a pristine cart, since ORDO_API_CUSTOMER_EMAIL points
            // at a real, persistent customer that may still be carrying a selection from a
            // previous run of this same test.
            [$status, $reset] = $this->asCustomer('PUT', "/rest/V1/ordo/carts/{$cartId}/free-gifts", [
                'selection' => ['skus' => []],
            ]);
            self::assertSame(200, $status, json_encode($reset));
            self::assertSame(0, $reset['used_slots']);

            // Eligibility reads the quote's live subtotal, which occasionally isn't yet
            // reflected the instant after the item-add call returns (observed directly against
            // this project's own local sandbox — a handful of consecutive real runs showed
            // earned_slots briefly still 0 right after add, correct on the very next read a
            // moment later). A short retry, not a longer fixed sleep, so the common case pays
            // no extra latency.
            $eligibility = null;
            for ($attempt = 0; $attempt < 15; $attempt++) {
                [$status, $eligibility] = $this->asCustomer(
                    'GET',
                    "/rest/V1/ordo/carts/{$cartId}/free-gift-eligibility"
                );
                self::assertSame(200, $status, json_encode($eligibility));
                if ($eligibility['earned_slots'] >= 1) {
                    break;
                }
                usleep(300_000);
            }
            self::assertGreaterThanOrEqual(1, $eligibility['earned_slots'], json_encode($eligibility));
            self::assertSame(0, $eligibility['used_slots']);
            self::assertContains($sku, $eligibility['eligible_skus']);

            [$status, $selected] = $this->asCustomer('PUT', "/rest/V1/ordo/carts/{$cartId}/free-gifts", [
                'selection' => ['skus' => [$sku]],
            ]);
            self::assertSame(200, $status, json_encode($selected));
            self::assertSame(1, $selected['used_slots']);
            self::assertSame($eligibility['earned_slots'] - 1, $selected['remaining_slots']);

            // A nonexistent (or not-owned) cart must not leak which case it is.
            [$status, $notFound] = $this->asCustomer('GET', '/rest/V1/ordo/carts/999999/free-gift-eligibility');
            self::assertSame(404, $status);
            self::assertStringContainsString('No such entity', $notFound['message']);
        } finally {
            // Guarantees this offer never lingers to inflate a later run's earned_slots, even
            // if an assertion above failed midway — see the sibling test's finally block for
            // the real, previously-observed bug this prevents.
            $this->asAdmin('DELETE', "/rest/V1/ordo/free-gift-offers/{$offerId}");
        }
    }
}
