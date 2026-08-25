<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignInterface;

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
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(CampaignInterface $campaign): bool;
}
