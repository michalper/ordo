<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ReorderCycle\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _construct(): void
    {
        $this->_init(
            \Ordo\Automation\Model\ReorderCycle::class,
            \Ordo\Automation\Model\ResourceModel\ReorderCycle::class
        );
    }
}
