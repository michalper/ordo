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

    public function testAddsEveryConfiguredTagToTheCustomerInContext(): void
    {
        $calls = [];
        $this->customerTagManager->expects(self::exactly(2))->method('addTag')
            ->willReturnCallback(function (int $customerId, string $tag) use (&$calls): void {
                $calls[] = [$customerId, $tag];
            });

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tags' => ['vip', 'loyal']]);

        self::assertSame([[42, 'vip'], [42, 'loyal']], $calls);
    }

    public function testTagsAreTrimmedAndEmptyOnesSkipped(): void
    {
        $this->customerTagManager->expects(self::once())->method('addTag')->with(42, 'vip');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['tags' => [' vip ', '', '  ']]);
    }

    public function testDoesNothingWhenContextIsMissingCustomerId(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTag');

        $context = [];
        $this->action->execute($context, ['tags' => ['vip']]);
    }

    public function testDoesNothingWhenParamsIsMissingTags(): void
    {
        $this->customerTagManager->expects(self::never())->method('addTag');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    public function testGetParamFieldsListsTags(): void
    {
        self::assertSame(['tags'], array_column($this->action->getParamFields(), 'name'));
    }
}
