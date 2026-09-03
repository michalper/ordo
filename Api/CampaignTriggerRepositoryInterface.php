<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Api\Data\CampaignTriggerSearchResultsInterface;

interface CampaignTriggerRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(CampaignTriggerInterface $trigger): CampaignTriggerInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): CampaignTriggerInterface;

    /**
     * Filter by campaign_id via searchCriteria to get every trigger on one campaign.
     */
    public function getList(SearchCriteriaInterface $searchCriteria): CampaignTriggerSearchResultsInterface;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
