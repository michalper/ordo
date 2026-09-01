<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\AddPoints;
use Ordo\Automation\Model\CustomerScoreManager;
use PHPUnit\Framework\TestCase;

class AddPointsTest extends TestCase
{
    private CustomerScoreManager&\PHPUnit\Framework\MockObject\MockObject $customerScoreManager;
    private AddPoints $action;

    protected function setUp(): void
    {
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->action = new AddPoints($this->customerScoreManager);
    }

    public function testAddsTheConfiguredPointsToTheCustomerInContext(): void
    {
        $this->customerScoreManager->expects(self::once())->method('addPoints')->with(42, 10);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['points' => '10']);
    }

    public function testAcceptsNegativePointsAsAPenalty(): void
    {
        $this->customerScoreManager->expects(self::once())->method('addPoints')->with(42, -5);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['points' => '-5']);
    }

    public function testDoesNothingWhenContextIsMissingCustomerId(): void
    {
        $this->customerScoreManager->expects(self::never())->method('addPoints');

        $context = [];
        $this->action->execute($context, ['points' => '10']);
    }

    public function testDoesNothingWhenPointsIsZeroOrMissing(): void
    {
        $this->customerScoreManager->expects(self::never())->method('addPoints');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
        $this->action->execute($context, ['points' => '0']);
    }
}
