<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard Magento admin grid collection (SearchResult-based), registered against the listing's
 * data source name via di.xml's UiComponent\DataProvider\CollectionFactory mapping — same
 * pattern as Model\ResourceModel\Campaign\Grid\Collection.
 */
class Collection extends SearchResult
{
}
