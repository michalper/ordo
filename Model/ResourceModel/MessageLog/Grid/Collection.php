<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageLog\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard admin grid collection — see Model\ResourceModel\Campaign\Grid\Collection for why
 * this is SearchResult-based rather than the plain AbstractCollection the rest of the module
 * uses.
 */
class Collection extends SearchResult
{
}
