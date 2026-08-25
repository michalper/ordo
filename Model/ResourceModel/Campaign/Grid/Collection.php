<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard Magento admin grid collection (SearchResult-based, not the plain AbstractCollection
 * used by the rest of the module) — registered against the listing's data source name via
 * di.xml's Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory mapping.
 */
class Collection extends SearchResult
{
    protected function _construct(): void
    {
        $this->_init(
            \Ordo\Automation\Model\Campaign::class,
            \Ordo\Automation\Model\ResourceModel\Campaign::class
        );
    }
}
