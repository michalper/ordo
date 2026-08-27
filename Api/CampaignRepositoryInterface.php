<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Api\Data\CampaignSearchResultsInterface;

interface CampaignRepositoryInterface
{
    /**
     * @return CampaignInterface
     * @throws CouldNotSaveException
     */
    public function save(CampaignInterface $campaign): CampaignInterface;

    /**
     * @return CampaignInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): CampaignInterface;

    /**
     * @return CampaignSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): CampaignSearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(CampaignInterface $campaign): bool;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
