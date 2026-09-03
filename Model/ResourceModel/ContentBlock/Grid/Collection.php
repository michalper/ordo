<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ContentBlock\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Standard admin grid collection — see Model\ResourceModel\Campaign\Grid\Collection /
 * Model\ResourceModel\ScoreRule\Grid\Collection for the pattern this mirrors. Unlike
 * ScoreRule's grid collection, ordo_content_block already has a "name" column, so no column
 * aliasing is needed here.
 */
class Collection extends SearchResult
{
}
