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
    /**
     * A campaign's trigger(s) live in ordo_campaign_trigger now (CampaignTriggerInterface —
     * a campaign can fire on more than one), not a column on ordo_campaign — the grid's
     * "Triggers" column needs every row's trigger events aggregated into one comma-separated
     * string, so it's a GROUP_CONCAT join here rather than a plain column select.
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()->joinLeft(
            ['ordo_campaign_trigger' => $this->getTable('ordo_campaign_trigger')],
            'ordo_campaign_trigger.campaign_id = main_table.entity_id',
            ['triggers' => new \Zend_Db_Expr('GROUP_CONCAT(DISTINCT ordo_campaign_trigger.trigger_event SEPARATOR \', \')')]
        )->group('main_table.entity_id');

        return $this;
    }
}
