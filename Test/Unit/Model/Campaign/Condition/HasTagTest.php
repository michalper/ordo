<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\HasTag;
use Ordo\Automation\Model\Config\Source\TagMatchMode;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class HasTagTest extends TestCase
{
    private CustomerTagManager&\PHPUnit\Framework\MockObject\MockObject $customerTagManager;
    private HasTag $condition;

    protected function setUp(): void
    {
        $this->customerTagManager = $this->createMock(CustomerTagManager::class);
        $this->condition = new HasTag($this->customerTagManager, new TagMatchMode());
    }

    public function testSatisfiedWhenCustomerHasAnyOfTheTagsDefaultMatchMode(): void
    {
        $this->customerTagManager->expects(self::once())->method('hasAnyTag')
            ->with(42, ['vip', 'loyal'])->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['tags' => ['vip', 'loyal']]));
    }

    public function testNotSatisfiedWhenCustomerHasNoneOfTheTags(): void
    {
        $this->customerTagManager->expects(self::once())->method('hasAnyTag')
            ->with(42, ['vip'])->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['tags' => ['vip']]));
    }

    public function testAllMatchModeUsesHasAllTags(): void
    {
        $this->customerTagManager->expects(self::once())->method('hasAllTags')
            ->with(42, ['vip', 'loyal'])->willReturn(true);
        $this->customerTagManager->expects(self::never())->method('hasAnyTag');

        self::assertTrue($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['tags' => ['vip', 'loyal'], 'match_mode' => 'all']
        ));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->customerTagManager->expects(self::never())->method('hasAnyTag');

        self::assertFalse($this->condition->isSatisfied([], ['tags' => ['vip']]));
    }

    public function testNotSatisfiedWhenParamsIsMissingTags(): void
    {
        $this->customerTagManager->expects(self::never())->method('hasAnyTag');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    public function testTagsAreTrimmedAndEmptyOnesDropped(): void
    {
        $this->customerTagManager->expects(self::once())->method('hasAnyTag')
            ->with(42, ['vip'])->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['tags' => [' vip ', '', '  ']]));
    }

    public function testResolveCustomerIdsDelegatesToGetCustomerIdsWithAnyTagByDefault(): void
    {
        $this->customerTagManager->expects(self::once())->method('getCustomerIdsWithAnyTag')
            ->with(['vip', 'loyal'])->willReturn([1, 2, 3]);

        self::assertSame([1, 2, 3], $this->condition->resolveCustomerIds(['tags' => ['vip', 'loyal']]));
    }

    public function testResolveCustomerIdsUsesAllTagsForAllMatchMode(): void
    {
        $this->customerTagManager->expects(self::once())->method('getCustomerIdsWithAllTags')
            ->with(['vip', 'loyal'])->willReturn([1]);
        $this->customerTagManager->expects(self::never())->method('getCustomerIdsWithAnyTag');

        self::assertSame([1], $this->condition->resolveCustomerIds(['tags' => ['vip', 'loyal'], 'match_mode' => 'all']));
    }

    public function testResolveCustomerIdsReturnsEmptyForNoTags(): void
    {
        $this->customerTagManager->expects(self::never())->method('getCustomerIdsWithAnyTag');

        self::assertSame([], $this->condition->resolveCustomerIds([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsTagsAndMatchMode(): void
    {
        self::assertSame(['tags', 'match_mode'], array_column($this->condition->getParamFields(), 'name'));
    }
}
