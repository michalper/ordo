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
     * @throws CouldNotSaveException
     */
    public function save(FreeGiftOfferInterface $offer): FreeGiftOfferInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): FreeGiftOfferInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferSearchResultsInterface;

    public function delete(FreeGiftOfferInterface $offer): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
