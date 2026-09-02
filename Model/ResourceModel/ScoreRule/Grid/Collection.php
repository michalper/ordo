<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ScoreRule\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard admin grid collection — see Model\ResourceModel\Campaign\Grid\Collection for why
 * this is SearchResult-based rather than the plain AbstractCollection the rest of the module
 * uses.
 */
class Collection extends SearchResult
{
    /**
     * ordo_score_rule has no "name" column — every other entity this module's
     * AbstractEntityActionsColumn::prepareDataSource() renders a delete-confirm for (Campaign,
     * Segment, FreeGiftOffer) does, since it unconditionally reads $item['name'] for that
     * confirm text. Aliasing attribute_code as "name" here is cheaper than teaching the shared
     * base class about entities with no display name field, for one entity that already has an
     * identifying string column.
     */
    protected function _initSelect(): void
    {
        parent::_initSelect();

        $this->getSelect()->columns(['name' => 'attribute_code']);
    }
}
