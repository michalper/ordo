<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferTierInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferTierSearchResultsInterface;

interface FreeGiftOfferTierRepositoryInterface
{
    /**
     * @return FreeGiftOfferTierInterface
     * @throws CouldNotSaveException
     */
    public function save(FreeGiftOfferTierInterface $tier): FreeGiftOfferTierInterface;

    /**
     * @return FreeGiftOfferTierInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): FreeGiftOfferTierInterface;

    /**
     * @return FreeGiftOfferTierSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferTierSearchResultsInterface;

    /**
     * @return bool
     */
    public function delete(FreeGiftOfferTierInterface $tier): bool;

    /**
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
