<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\Data\OfferSearchResultsInterface;

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
     * @return OfferSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): OfferSearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(OfferInterface $offer): bool;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
