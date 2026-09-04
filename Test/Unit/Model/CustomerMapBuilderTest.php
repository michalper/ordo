<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Ordo\Automation\Model\CustomerMapBuilder;
use PHPUnit\Framework\TestCase;

class CustomerMapBuilderTest extends TestCase
{
    public function testBuildReturnsEmptyMapWithoutQueryingWhenNoIds(): void
    {
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->expects(self::never())->method('addFilter');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::never())->method('getList');

        $builder = new CustomerMapBuilder($customerRepository, $searchCriteriaBuilder);

        self::assertSame([], $builder->build([]));
    }

    public function testBuildFiltersByDeduplicatedEntityIdsAndIndexesResultById(): void
    {
        $searchCriteria = $this->createStub(SearchCriteria::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->expects(self::once())->method('addFilter')
            ->with('entity_id', [5, 7], 'in')
            ->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $customerFive = $this->createStub(CustomerInterface::class);
        $customerFive->method('getId')->willReturn(5);
        $customerSeven = $this->createStub(CustomerInterface::class);
        $customerSeven->method('getId')->willReturn(7);

        $searchResults = $this->createStub(CustomerSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$customerFive, $customerSeven]);

        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('getList')->willReturn($searchResults);

        $builder = new CustomerMapBuilder($customerRepository, $searchCriteriaBuilder);

        self::assertSame(
            [5 => $customerFive, 7 => $customerSeven],
            $builder->build([5, 7, 5])
        );
    }
}
