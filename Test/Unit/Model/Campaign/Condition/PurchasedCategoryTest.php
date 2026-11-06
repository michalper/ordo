<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\PurchasedCategory;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class PurchasedCategoryTest extends TestCase
{
    private PurchasedProductResolver&\PHPUnit\Framework\MockObject\MockObject $purchasedProductResolver;
    private PurchasedCategory $condition;

    protected function setUp(): void
    {
        $this->purchasedProductResolver = $this->createMock(PurchasedProductResolver::class);
        $this->condition = new PurchasedCategory($this->purchasedProductResolver);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenCustomerPurchasedFromTheCategory(): void
    {
        $this->purchasedProductResolver->method('hasPurchasedInCategory')->willReturnMap([[42, 15, true]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['category_id' => '15']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerNeverPurchasedFromTheCategory(): void
    {
        $this->purchasedProductResolver->method('hasPurchasedInCategory')->willReturnMap([[42, 15, false]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['category_id' => '15']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedInCategory');

        self::assertFalse($this->condition->isSatisfied([], ['category_id' => '15']));
    }

    public function testNotSatisfiedWhenParamsIsMissingCategoryId(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedInCategory');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    public function testNotSatisfiedWhenCategoryIdIsNonNumeric(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedInCategory');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['category_id' => 'not-a-number']));
    }

    public function testNotSatisfiedWhenCategoryIdIsZeroOrNegative(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedInCategory');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['category_id' => '0']));
        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['category_id' => '-1']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsDelegatesToPurchasedProductResolver(): void
    {
        $this->purchasedProductResolver->method('getCustomerIdsWhoPurchasedInCategory')
            ->willReturnMap([[15, [7]]]);

        self::assertSame([7], $this->condition->resolveCustomerIds(['category_id' => '15']));
    }

    public function testResolveCustomerIdsFailsClosedOnNonNumericCategoryId(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('getCustomerIdsWhoPurchasedInCategory');

        self::assertSame([], $this->condition->resolveCustomerIds(['category_id' => 'not-a-number']));
    }

    public function testGetParamFieldsListsCategoryId(): void
    {
        self::assertSame(['category_id'], array_column($this->condition->getParamFields(), 'name'));
    }
}
