<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\ContextTargetResolver;
use Ordo\Automation\Model\Campaign\Action\ShowPopup;
use Ordo\Automation\Model\Campaign\RewardTierSelector;
use Ordo\Automation\Model\CouponGenerator;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection as PendingPopupCollection;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ShowPopupTest extends TestCase
{
    private PendingPopupFactory&\PHPUnit\Framework\MockObject\MockObject $pendingPopupFactory;
    private PendingPopupResource&\PHPUnit\Framework\MockObject\MockObject $pendingPopupResource;
    private PendingPopupCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $pendingPopupCollectionFactory;
    private PendingPopupCollection&\PHPUnit\Framework\MockObject\MockObject $pendingPopupCollection;
    private Config&\PHPUnit\Framework\MockObject\MockObject $config;
    private DateTime&\PHPUnit\Framework\MockObject\MockObject $dateTime;
    private RewardTierSelector&\PHPUnit\Framework\MockObject\MockObject $rewardTierSelector;
    private CouponGenerator&\PHPUnit\Framework\MockObject\MockObject $couponGenerator;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private ShowPopup $action;

    protected function setUp(): void
    {
        $this->pendingPopupFactory = $this->createMock(PendingPopupFactory::class);
        $this->pendingPopupResource = $this->createMock(PendingPopupResource::class);
        $this->pendingPopupCollectionFactory = $this->createMock(PendingPopupCollectionFactory::class);
        $this->pendingPopupCollection = $this->createMock(PendingPopupCollection::class);
        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->pendingPopupCollection);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getPopupFrequencyCapHours')->willReturn(24);
        $this->pendingPopupCollection->method('targetHasRecentlyReceivedPopup')->willReturn(false);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->rewardTierSelector = $this->createMock(RewardTierSelector::class);
        $this->couponGenerator = $this->createMock(CouponGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = $this->makeAction();
    }

    private function makeAction(): ShowPopup
    {
        return new ShowPopup(
            $this->pendingPopupFactory,
            $this->pendingPopupResource,
            $this->pendingPopupCollectionFactory,
            $this->config,
            $this->dateTime,
            new ContextTargetResolver(),
            $this->rewardTierSelector,
            $this->couponGenerator,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesPopupForCustomer(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setCustomerId')->with(42);
        $popup->expects(self::once())->method('setVisitorId')->with(null);
        $popup->expects(self::once())->method('setHeadline')->with('Hello!');
        $popup->expects(self::once())->method('setBody')->with('Come back soon');
        $popup->expects(self::once())->method('setCtaLabel')->with('Shop now');
        $popup->expects(self::once())->method('setCtaUrl')->with('https://example.test/sale');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Hello!',
            'body' => 'Come back soon',
            'cta_label' => 'Shop now',
            'cta_url' => 'https://example.test/sale',
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesPopupForAnonymousVisitor(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setCustomerId')->with(null);
        $popup->expects(self::once())->method('setVisitorId')->with('v1');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['visitor_id' => 'v1'];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLeavesOptionalParamsNullWhenBlank(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setBody')->with(null);
        $popup->expects(self::once())->method('setCtaLabel')->with(null);
        $popup->expects(self::once())->method('setCtaUrl')->with(null);
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenHeadlineMissing(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsSilentlyWhenTargetRecentlyReceivedAPopup(): void
    {
        $collection = $this->createStub(PendingPopupCollection::class);
        $collection->method('targetHasRecentlyReceivedPopup')->willReturn(true);
        $collectionFactory = $this->createStub(PendingPopupCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $action = new ShowPopup(
            $this->pendingPopupFactory,
            $this->pendingPopupResource,
            $collectionFactory,
            $this->config,
            $this->dateTime,
            new ContextTargetResolver(),
            $this->rewardTierSelector,
            $this->couponGenerator,
            $this->logger
        );

        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->pendingPopupResource->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteIgnoresFrequencyCapWhenDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getPopupFrequencyCapHours')->willReturn(0);
        $this->action = $this->makeAction();

        $popup = $this->createStub(PendingPopup::class);
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsHeadlineBodyCtaAndRewardTiers(): void
    {
        self::assertSame(
            ['headline', 'body', 'cta_label', 'cta_url', 'reward_tiers'],
            array_column($this->action->getParamFields(), 'name')
        );
    }

    /**
     * @return array{label: string, weight: int, coupon_rule_id: int|null}[]
     */
    private function tiers(): array
    {
        return [
            ['label' => '10% off', 'weight' => 50, 'coupon_rule_id' => 3],
            ['label' => 'Try again', 'weight' => 50, 'coupon_rule_id' => null],
        ];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithARealArrayOfRewardTiersPicksATierAndMintsItsCoupon(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setRewardLabel')->with('10% off');
        $popup->expects(self::once())->method('setCouponCode')->with('ORDO-WHEEL-ABC123');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $this->rewardTierSelector->expects(self::once())->method('selectTier')->with($this->tiers())
            ->willReturn(['label' => '10% off', 'weight' => 50, 'coupon_rule_id' => 3]);
        $this->couponGenerator->expects(self::once())->method('generate')
            ->with(3, 'ORDO-WHEEL', campaignId: 7)
            ->willReturn('ORDO-WHEEL-ABC123');

        $context = ['customer_id' => 42, 'campaign_id' => 7];
        $this->action->execute($context, ['headline' => 'Spin to win!', 'reward_tiers' => $this->tiers()]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithARewardTiersJsonStringDecodesItItself(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setRewardLabel')->with('10% off');
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $this->rewardTierSelector->expects(self::once())->method('selectTier')->with($this->tiers())
            ->willReturn(['label' => '10% off', 'weight' => 50, 'coupon_rule_id' => 3]);
        $this->couponGenerator->method('generate')->willReturn('ORDO-WHEEL-ABC123');

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Spin to win!',
            'reward_tiers' => (string) json_encode($this->tiers()),
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithATryAgainTierSetsRewardLabelButNeverGeneratesACoupon(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setRewardLabel')->with('Try again');
        $popup->expects(self::never())->method('setCouponCode');
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $this->rewardTierSelector->method('selectTier')
            ->willReturn(['label' => 'Try again', 'weight' => 50, 'coupon_rule_id' => null]);
        $this->couponGenerator->expects(self::never())->method('generate');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Spin to win!', 'reward_tiers' => $this->tiers()]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndLeavesCouponCodeUnsetWhenCouponGenerationThrows(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setRewardLabel')->with('10% off');
        $popup->expects(self::never())->method('setCouponCode');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $this->rewardTierSelector->method('selectTier')
            ->willReturn(['label' => '10% off', 'weight' => 50, 'coupon_rule_id' => 3]);
        $this->couponGenerator->method('generate')->willThrowException(new \RuntimeException('no such rule'));
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Spin to win!', 'reward_tiers' => $this->tiers()]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithEmptyRewardTiersBehavesAsAPlainPopup(): void
    {
        $popup = $this->createStub(PendingPopup::class);
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->rewardTierSelector->expects(self::never())->method('selectTier');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!', 'reward_tiers' => []]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithUnparsableRewardTiersJsonBehavesAsAPlainPopup(): void
    {
        $popup = $this->createStub(PendingPopup::class);
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->rewardTierSelector->expects(self::never())->method('selectTier');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!', 'reward_tiers' => 'not json at all']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDropsMalformedRewardTierEntriesButKeepsValidOnes(): void
    {
        $popup = $this->createStub(PendingPopup::class);
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $this->rewardTierSelector->expects(self::once())->method('selectTier')
            ->with([['label' => 'Valid', 'weight' => 10.0, 'coupon_rule_id' => null]])
            ->willReturn(['label' => 'Valid', 'weight' => 10.0, 'coupon_rule_id' => null]);

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Hello!',
            'reward_tiers' => [
                'not an array',
                ['weight' => 10], // missing label
                ['label' => '', 'weight' => 10], // blank label
                ['label' => 'Bad weight', 'weight' => 'fifty'], // wrong weight type
                ['label' => 'Bad weight 2', 'weight' => ['oops']], // wrong weight type
                ['label' => 'Valid', 'weight' => 10, 'coupon_rule_id' => 'not-an-int'],
            ],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAcceptsAFloatCouponRuleIdAndCastsItToInt(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->method('setRewardLabel');
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $this->rewardTierSelector->expects(self::once())->method('selectTier')
            ->with([['label' => 'Half off', 'weight' => 1.0, 'coupon_rule_id' => 3]])
            ->willReturn(['label' => 'Half off', 'weight' => 1.0, 'coupon_rule_id' => 3]);
        $this->couponGenerator->expects(self::once())->method('generate')->with(3, 'ORDO-WHEEL', campaignId: null)
            ->willReturn('ORDO-WHEEL-XYZ');

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Hello!',
            'reward_tiers' => [['label' => 'Half off', 'weight' => 1, 'coupon_rule_id' => 3.0]],
        ]);
    }
}
