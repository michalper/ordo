<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferSearchResultsInterface;

interface FreeGiftOfferRepositoryInterface
{
    /**
     * @return FreeGiftOfferInterface
     * @throws CouldNotSaveException
     */
    public function save(FreeGiftOfferInterface $offer): FreeGiftOfferInterface;

    /**
     * @return FreeGiftOfferInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): FreeGiftOfferInterface;

    /**
     * @return FreeGiftOfferSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferSearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(FreeGiftOfferInterface $offer): bool;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
