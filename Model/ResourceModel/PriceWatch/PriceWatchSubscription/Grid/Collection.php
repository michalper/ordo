<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard admin grid collection — see Model\ResourceModel\Campaign\Grid\Collection for why
 * this is SearchResult-based rather than the plain AbstractCollection the rest of the module
 * uses. mainTable/resourceModel wired in etc/di.xml.
 */
class Collection extends SearchResult
{
}
