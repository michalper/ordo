<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * Wraps the SKU list for FreeGiftManagementInterface::selectGifts() in a real data object
 * instead of a raw `array $skus` parameter. Magento's WebAPI request-body/path-param overrider
 * (Magento\Webapi\Controller\Rest\ParamsOverrider::overrideRequestBodyIdWithPathParam) treats
 * any single-top-level-key body whose value is itself an array as a possible nested data
 * object and reflects its declared type to look for a settable path-param property — for a
 * scalar array type ("string[]") that reflection crashes with
 * `ReflectionException: Class "string[]" does not exist` (found by actually calling this
 * endpoint against a live instance, not assumed from reading the WebAPI framework code). A real
 * class here gives that reflection something valid to inspect (it finds no `setCartId()`,
 * no-ops, and moves on), sidestepping the bug entirely.
 */
interface FreeGiftSelectionInterface
{
    /**
     * @return string[]
     */
    public function getSkus(): array;

    /**
     * @param string[] $skus
     * @return $this
     */
    public function setSkus(array $skus): self;
}
