<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\AddLoyaltyPoints;
use Ordo\Automation\Model\LoyaltyPointsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AddLoyaltyPointsTest extends TestCase
{
    private LoyaltyPointsManager&\PHPUnit\Framework\MockObject\MockObject $loyaltyPointsManager;
    private AddLoyaltyPoints $action;

    protected function setUp(): void
    {
        $this->loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $this->action = new AddLoyaltyPoints($this->loyaltyPointsManager);
    }

    public function testAwardsTheConfiguredPointsToTheCustomerInContext(): void
    {
        $this->loyaltyPointsManager->expects(self::once())->method('awardBonus')
            ->with(42, 100, self::isString());

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['points' => '100']);
    }

    public function testDoesNothingWhenContextIsMissingCustomerId(): void
    {
        $this->loyaltyPointsManager->expects(self::never())->method('awardBonus');

        $context = [];
        $this->action->execute($context, ['points' => '100']);
    }

    public function testDoesNothingWhenPointsIsZeroOrMissing(): void
    {
        $this->loyaltyPointsManager->expects(self::never())->method('awardBonus');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
        $this->action->execute($context, ['points' => '0']);
    }

    /**
     * Unlike AddPoints (a lead-scoring penalty rule can be negative), this ledger has no
     * clawback/penalty concept - see AddLoyaltyPoints's own docblock.
     */
    public function testDoesNothingWhenPointsIsNegative(): void
    {
        $this->loyaltyPointsManager->expects(self::never())->method('awardBonus');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['points' => '-5']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsPoints(): void
    {
        self::assertSame(['points'], array_column($this->action->getParamFields(), 'name'));
    }
}
