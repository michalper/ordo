<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OrderApprovalInterface;

/**
 * Read-only — decisions are made through OrderApprovalManagementInterface::approveByToken()/
 * rejectByToken(), not by writing fields directly. The token itself is deliberately not part
 * of Data\OrderApprovalInterface, so it never round-trips through this read API.
 */
interface OrderApprovalRepositoryInterface
{
    /**
     * @return OrderApprovalInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): OrderApprovalInterface;

    /**
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
