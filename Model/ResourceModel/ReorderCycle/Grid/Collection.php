<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ReorderCycle\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * SearchResult takes its table/resource model via constructor arguments (wired in
 * etc/di.xml), not via _init() in _construct() — see Campaign\Grid\Collection.
 */
class Collection extends SearchResult
{
}
