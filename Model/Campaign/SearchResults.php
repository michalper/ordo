<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Api\Data\CampaignSearchResultsInterface;

/**
 * Trivial subclass, not just a di.xml preference straight to the generic
 * \Magento\Framework\Api\SearchResults — PHP's return-type covariance requires the actual
 * returned object to genuinely implement CampaignSearchResultsInterface, which the generic
 * class doesn't (found by actually calling GET /V1/ordo/campaigns and hitting a TypeError).
 * Matches Magento core's own pattern (e.g. Magento\Catalog\Model\ProductSearchResults).
 */
class SearchResults extends \Magento\Framework\Api\SearchResults implements CampaignSearchResultsInterface
{
}
