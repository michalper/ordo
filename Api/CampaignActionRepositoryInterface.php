<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignActionInterface;
use Ordo\Automation\Api\Data\CampaignActionSearchResultsInterface;

interface CampaignActionRepositoryInterface
{
    /**
     * @return CampaignActionInterface
     * @throws CouldNotSaveException
     */
    public function save(CampaignActionInterface $action): CampaignActionInterface;

    /**
     * @return CampaignActionInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): CampaignActionInterface;

    /**
     * Filter by campaign_id via searchCriteria to get every action on one campaign.
     *
     * @return CampaignActionSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): CampaignActionSearchResultsInterface;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
