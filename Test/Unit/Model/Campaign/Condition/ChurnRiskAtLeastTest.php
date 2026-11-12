<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\ChurnRiskAtLeast;
use Ordo\Automation\Model\ChurnRisk\ChurnRiskCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ChurnRiskAtLeastTest extends TestCase
{
    private ChurnRiskCalculator&\PHPUnit\Framework\MockObject\MockObject $churnRiskCalculator;
    private ChurnRiskAtLeast $condition;

    protected function setUp(): void
    {
        $this->churnRiskCalculator = $this->createMock(ChurnRiskCalculator::class);
        $this->condition = new ChurnRiskAtLeast($this->churnRiskCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenChurnRiskRatioMeetsTheThreshold(): void
    {
        $this->churnRiskCalculator->method('getChurnRiskRatioForCustomer')->willReturnMap([[42, 1.3]]);

        self::assertTrue(
            $this->condition->isSatisfied(['customer_id' => 42], ['churn_risk_ratio_at_least' => '1.2'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenChurnRiskRatioEqualsTheThreshold(): void
    {
        $this->churnRiskCalculator->method('getChurnRiskRatioForCustomer')->willReturnMap([[42, 1.2]]);

        self::assertTrue(
            $this->condition->isSatisfied(['customer_id' => 42], ['churn_risk_ratio_at_least' => '1.2'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenChurnRiskRatioIsBelowTheThreshold(): void
    {
        $this->churnRiskCalculator->method('getChurnRiskRatioForCustomer')->willReturnMap([[42, 0.9]]);

        self::assertFalse(
            $this->condition->isSatisfied(['customer_id' => 42], ['churn_risk_ratio_at_least' => '1.2'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerHasNoChurnRiskBaseline(): void
    {
        $this->churnRiskCalculator->method('getChurnRiskRatioForCustomer')->willReturnMap([[42, null]]);

        self::assertFalse(
            $this->condition->isSatisfied(['customer_id' => 42], ['churn_risk_ratio_at_least' => '1.2'])
        );
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->churnRiskCalculator->expects(self::never())->method('getChurnRiskRatioForCustomer');

        self::assertFalse($this->condition->isSatisfied([], ['churn_risk_ratio_at_least' => '1.2']));
    }

    public function testNotSatisfiedWhenRatioAtLeastIsMissing(): void
    {
        $this->churnRiskCalculator->expects(self::never())->method('getChurnRiskRatioForCustomer');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsFiltersByChurnRiskRatioInclusively(): void
    {
        $this->churnRiskCalculator->method('getChurnRiskRatiosForAllCustomers')->willReturn([
            1 => 1.2,
            2 => 0.5,
            3 => 2.0,
        ]);

        self::assertSame(
            [1, 3],
            $this->condition->resolveCustomerIds(['churn_risk_ratio_at_least' => '1.2'])
        );
    }

    public function testResolveCustomerIdsFailsClosedOnNonNumericRatio(): void
    {
        $this->churnRiskCalculator->expects(self::never())->method('getChurnRiskRatiosForAllCustomers');

        self::assertSame([], $this->condition->resolveCustomerIds(['churn_risk_ratio_at_least' => 'not-a-number']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsChurnRiskRatioAtLeast(): void
    {
        self::assertSame(
            ['churn_risk_ratio_at_least'],
            array_column($this->condition->getParamFields(), 'name')
        );
    }
}
