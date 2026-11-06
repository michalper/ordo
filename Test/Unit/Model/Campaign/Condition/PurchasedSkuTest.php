<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\PurchasedSku;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class PurchasedSkuTest extends TestCase
{
    private PurchasedProductResolver&\PHPUnit\Framework\MockObject\MockObject $purchasedProductResolver;
    private PurchasedSku $condition;

    protected function setUp(): void
    {
        $this->purchasedProductResolver = $this->createMock(PurchasedProductResolver::class);
        $this->condition = new PurchasedSku($this->purchasedProductResolver);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenCustomerPurchasedTheSku(): void
    {
        $this->purchasedProductResolver->method('hasPurchasedSku')->willReturnMap([[42, '24-MB01', true]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['sku' => '24-MB01']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerNeverPurchasedTheSku(): void
    {
        $this->purchasedProductResolver->method('hasPurchasedSku')->willReturnMap([[42, '24-MB01', false]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['sku' => '24-MB01']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedSku');

        self::assertFalse($this->condition->isSatisfied([], ['sku' => '24-MB01']));
    }

    public function testNotSatisfiedWhenParamsIsMissingSku(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedSku');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    public function testNotSatisfiedWhenSkuIsBlank(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('hasPurchasedSku');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['sku' => '   ']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsDelegatesToPurchasedProductResolver(): void
    {
        $this->purchasedProductResolver->method('getCustomerIdsWhoPurchasedSku')
            ->willReturnMap([['24-MB01', [5, 6]]]);

        self::assertSame([5, 6], $this->condition->resolveCustomerIds(['sku' => '24-MB01']));
    }

    public function testResolveCustomerIdsFailsClosedOnEmptySku(): void
    {
        $this->purchasedProductResolver->expects(self::never())->method('getCustomerIdsWhoPurchasedSku');

        self::assertSame([], $this->condition->resolveCustomerIds(['sku' => '']));
    }
}
