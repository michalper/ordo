<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\AddTag;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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

    public function testAddsEveryConfiguredTagToTheCustomerInContext(): void
    {
        $this->customerTagManager->expects(self::once())->method('addTags')->with(42, ['vip', 'loyal']);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tags' => ['vip', 'loyal']]);
    }

    public function testTagsAreTrimmedAndEmptyOnesSkipped(): void
    {
        $this->customerTagManager->expects(self::once())->method('addTags')->with(42, ['vip']);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tags' => [' vip ', '', '  ']]);
    }

    public function testDoesNothingWhenContextIsMissingCustomerId(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTags');

        $context = [];
        $this->action->execute($context, ['tags' => ['vip']]);
    }

    public function testDoesNothingWhenParamsIsMissingTags(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTags');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    public function testDoesNothingWhenEveryTagIsBlankAfterTrimming(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTags');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tags' => ['', '  ']]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsTags(): void
    {
        self::assertSame(['tags'], array_column($this->action->getParamFields(), 'name'));
    }
}
