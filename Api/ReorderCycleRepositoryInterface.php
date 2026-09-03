<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\ReorderCycleInterface;
use Ordo\Automation\Api\Data\ReorderCycleSearchResultsInterface;

/**
 * Read-only — reorder cycles are computed by Cron\CalculateReorderCycle, not written via API.
 */
interface ReorderCycleRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): ReorderCycleInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ReorderCycleSearchResultsInterface;
}
