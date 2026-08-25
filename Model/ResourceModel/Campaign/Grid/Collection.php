<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard Magento admin grid collection (SearchResult-based, not the plain AbstractCollection
 * used by the rest of the module) — registered against the listing's data source name via
 * di.xml's Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory mapping.
 *
 * Unlike AbstractCollection, SearchResult takes its table/resource model via constructor
 * arguments (wired in etc/di.xml), not via _init() in _construct().
 */
class Collection extends SearchResult
{
}
