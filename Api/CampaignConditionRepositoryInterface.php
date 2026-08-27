<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignConditionInterface;
use Ordo\Automation\Api\Data\CampaignConditionSearchResultsInterface;

interface CampaignConditionRepositoryInterface
{
    /**
     * @return CampaignConditionInterface
     * @throws CouldNotSaveException
     */
    public function save(CampaignConditionInterface $condition): CampaignConditionInterface;

    /**
     * @return CampaignConditionInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): CampaignConditionInterface;

    /**
     * Filter by campaign_id via searchCriteria to get every condition on one campaign, e.g.
     * searchCriteria[filterGroups][0][filters][0][field]=campaign_id&...[value]=5.
     *
     * @return CampaignConditionSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): CampaignConditionSearchResultsInterface;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
