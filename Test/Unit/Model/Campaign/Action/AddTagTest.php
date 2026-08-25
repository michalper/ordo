<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\AddTag;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\TestCase;

class AddTagTest extends TestCase
{
    private CustomerTagManager&\PHPUnit\Framework\MockObject\MockObject $customerTagManager;
    private AddTag $action;

    protected function setUp(): void
    {
        $this->customerTagManager = $this->createMock(CustomerTagManager::class);
        $this->action = new AddTag($this->customerTagManager);
    }

    public function testAddsTheConfiguredTagToTheCustomerInContext(): void
    {
        $this->customerTagManager->expects(self::once())->method('addTag')->with(42, 'vip');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tag' => 'vip']);
    }

    public function testDoesNothingWhenContextIsMissingCustomerId(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTag');

        $context = [];
        $this->action->execute($context, ['tag' => 'vip']);
    }

    public function testDoesNothingWhenParamsIsMissingTag(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTag');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }
}
