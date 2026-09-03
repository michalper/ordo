<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferProductInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferProductSearchResultsInterface;

interface FreeGiftOfferProductRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(FreeGiftOfferProductInterface $product): FreeGiftOfferProductInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): FreeGiftOfferProductInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferProductSearchResultsInterface;

    public function delete(FreeGiftOfferProductInterface $product): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function deleteById(int $entityId): bool;
}
