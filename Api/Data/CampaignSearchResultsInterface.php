<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * The generic SearchResultsInterface::getItems()/setItems() have no way to tell the WebAPI
 * output processor what's inside the array — without this, list endpoints (e.g.
 * GET /V1/ordo/campaigns) silently serialize every item as an empty object. Found by actually
 * calling the endpoint (see VERIFICATION.md); the fix is this standard Magento pattern, one
 * dedicated SearchResults interface per entity with a typed @return docblock. The docblock's
 * class reference must be fully qualified — Magento's reflection-based doc parser (used to
 * build the WebAPI schema) does not resolve `use` imports, only literal FQCNs.
 */
interface CampaignSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\CampaignInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\CampaignInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
