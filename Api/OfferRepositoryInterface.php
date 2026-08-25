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
     * @return OfferInterface
     * @throws CouldNotSaveException
     */
    public function save(OfferInterface $offer): OfferInterface;

    /**
     * @return OfferInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): OfferInterface;

    /**
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(OfferInterface $offer): bool;
}
