<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\MessageEngaged;
use Ordo\Automation\Model\Config\Source\CampaignOptions;
use Ordo\Automation\Model\Config\Source\MessageEngagementType;
use Ordo\Automation\Model\Email\MessageEngagementResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class MessageEngagedTest extends TestCase
{
    private MessageEngagementResolver&\PHPUnit\Framework\MockObject\MockObject $resolver;
    private CampaignOptions $campaignOptions;
    private MessageEngaged $condition;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(MessageEngagementResolver::class);
        $this->campaignOptions = $this->createStub(CampaignOptions::class);
        $this->campaignOptions->method('toOptionArray')->willReturn([]);
        $this->condition = new MessageEngaged($this->resolver, $this->campaignOptions, new MessageEngagementType());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenResolverReportsEngagement(): void
    {
        $this->resolver->method('hasEngaged')->with(42, 7, 'opened', 14)->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['campaign_id' => 7, 'engagement_type' => 'opened', 'engagement_within_days' => 14]
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenResolverReportsNoEngagement(): void
    {
        $this->resolver->method('hasEngaged')->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['campaign_id' => 7, 'engagement_type' => 'opened', 'engagement_within_days' => 14]
        ));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->resolver->expects(self::never())->method('hasEngaged');

        self::assertFalse($this->condition->isSatisfied(
            [],
            ['campaign_id' => 7, 'engagement_type' => 'opened', 'engagement_within_days' => 14]
        ));
    }

    public function testNotSatisfiedWhenCampaignIdIsMissing(): void
    {
        $this->resolver->expects(self::never())->method('hasEngaged');

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['engagement_type' => 'opened', 'engagement_within_days' => 14]
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsDelegatesToTheResolver(): void
    {
        $this->resolver->method('getCustomerIdsWithEngagement')->with(7, 'clicked', 30)->willReturn([1, 2]);

        self::assertSame([1, 2], $this->condition->resolveCustomerIds([
            'campaign_id' => 7,
            'engagement_type' => 'clicked',
            'engagement_within_days' => 30,
        ]));
    }

    public function testResolveCustomerIdsFailsClosedOnMissingCampaignId(): void
    {
        $this->resolver->expects(self::never())->method('getCustomerIdsWithEngagement');

        self::assertSame([], $this->condition->resolveCustomerIds([
            'engagement_type' => 'clicked',
            'engagement_within_days' => 30,
        ]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsCampaignAndEngagementFields(): void
    {
        self::assertSame(
            ['campaign_id', 'engagement_type', 'engagement_within_days'],
            array_column($this->condition->getParamFields(), 'name')
        );
    }
}
