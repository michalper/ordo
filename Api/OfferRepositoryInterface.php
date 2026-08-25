<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;

interface OfferRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(OfferInterface $offer): OfferInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): OfferInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    public function delete(OfferInterface $offer): bool;
}
