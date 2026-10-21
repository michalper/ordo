<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\Store;
use Ordo\Automation\Api\Data\OrderApprovalDecisionLinksInterface;
use Ordo\Automation\Cron\EscalateStalePendingApprovals;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\Collection as ApprovalCollection;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as ApprovalCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EscalateStalePendingApprovalsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private Config $config;
    private ApprovalCollectionFactory $approvalCollectionFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private ReminderEmailSender $reminderEmailSender;
    private OrderApprovalManagement $orderApprovalManagement;
    private TriggerOutcomeLogger $triggerOutcomeLogger;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isOrderApprovalEnabled')->willReturn(true);
        $this->config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $this->config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        $this->config->method('getOrderApprovalEscalationChainEmails')->willReturn([]);
        $this->approvalCollectionFactory = $this->createMock(ApprovalCollectionFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->reminderEmailSender = $this->createStub(ReminderEmailSender::class);
        $this->orderApprovalManagement = $this->createStub(OrderApprovalManagement::class);
        $this->triggerOutcomeLogger = $this->createStub(TriggerOutcomeLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): EscalateStalePendingApprovals
    {
        return new EscalateStalePendingApprovals(
            $this->config,
            $this->approvalCollectionFactory,
            $this->orderApprovalResource,
            $this->orderCollectionFactory,
            $this->reminderEmailSender,
            $this->orderApprovalManagement,
            $this->triggerOutcomeLogger,
            $this->makeCronRunLogger($this->logger)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenApprovalDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(false);
        $this->config = $config;

        $this->approvalCollectionFactory->expects(self::never())->method('create');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsApprovalAtMaxEscalations(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(3);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $this->orderCollectionFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('0 order approval escalations'));

        $this->makeCron()->execute();
    }

    /**
     * A chain IS configured and the approval is already at its reminder cap at tier 0 - the next
     * reminder must go to the first chain email (tier 1), with escalation_tier advanced and
     * reminders_sent reset to 1 (this send's own reminder), not left at the old tier's count.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAdvancesTierAndRemindsChainRecipient(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(true);
        $config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        $config->method('getOrderApprovalEscalationChainEmails')->willReturn(['tier1@example.com']);
        $this->config = $config;

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(3);
        $approval->method('getEscalationTier')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $approval->method('getToken')->willReturn('tok');
        $setDataCalls = [];
        $approval->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getGrandTotal')->willReturn(150.0);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getStore')->willReturn($store);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalManagement->method('buildDecisionLinks')
            ->willReturn($this->createStub(OrderApprovalDecisionLinksInterface::class));
        $sentTo = null;
        $this->reminderEmailSender->method('send')->willReturnCallback(
            function (string $template, array $vars, string $to) use (&$sentTo): void {
                $sentTo = $to;
            }
        );

        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        $this->makeCron()->execute();

        self::assertSame('tier1@example.com', $sentTo);
        self::assertSame([['escalation_tier', 1], ['reminders_sent', 1]], $setDataCalls);
    }

    /**
     * When a claim that DID advance the tier (cap reached, chain has a next email) is then
     * followed by a failed send, the rollback must restore escalation_tier as well as
     * reminders_sent - not just reminders_sent, as a same-tier reminder's rollback does.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRollsBackEscalationTierWhenSendFailsAfterATierAdvance(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(true);
        $config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        $config->method('getOrderApprovalEscalationChainEmails')->willReturn(['tier1@example.com']);
        $this->config = $config;

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(3);
        $approval->method('getEscalationTier')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $setDataCalls = [];
        $approval->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::exactly(2))->method('save')->with($approval);
        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();

        self::assertSame(
            [['escalation_tier', 1], ['reminders_sent', 1], ['reminders_sent', 3], ['escalation_tier', 0]],
            $setDataCalls
        );
    }

    /**
     * claimRecipientEmail()'s own defensive null-return branches - guarding against a chain that
     * shrank since escalation_tier/reminders_sent were set on this row - are never reached via a
     * normal execute() pass (peekRecipientEmail's pre-filter mirrors the exact same logic, so
     * anything claimRecipientEmail would reject is already filtered out upfront). Reached here by
     * having the approval mock report a DIFFERENT (already-shrunk) chain position on its second
     * read (inside the main loop / claim itself) than on its first (the pre-filter's peek read) -
     * simulating exactly the "chain shrank since it was set" scenario both docblocks describe.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenClaimDisagreesWithPeekAtAWithinTierCap(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(true);
        $config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        // Only tier 1's email is configured - tier 2 has none.
        $config->method('getOrderApprovalEscalationChainEmails')->willReturn(['tier1@example.com']);
        $this->config = $config;

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        // 1st read: peekRecipientEmail's pre-filter (tier 1, chain has it -> passes).
        // 2nd read: the main loop's "before claim" snapshot (still tier 1).
        // 3rd read: claimRecipientEmail's own internal read (tier 2 - shrunk chain has no email).
        $approval->method('getEscalationTier')->willReturnOnConsecutiveCalls(1, 1, 2);
        $approval->method('getOrderId')->willReturn(7);
        $approval->expects(self::never())->method('setData');

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getEntityId')->willReturn(7);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->expects(self::once())->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('0 order approval escalations'));

        $this->makeCron()->execute();
    }

    /**
     * Same defensive-mismatch scenario as above, but at the cap (remindersSent >= max) - the
     * branch guarding against a fully-exhausted-since-set chain when advancing to a brand new
     * next tier.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenClaimDisagreesWithPeekAtTheTierCap(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(true);
        $config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        // No chain configured at all past tier 0's own admin email.
        $config->method('getOrderApprovalEscalationChainEmails')->willReturn([]);
        $this->config = $config;

        $approval = $this->createMock(OrderApproval::class);
        // 1st/2nd read (peek pre-filter, then the main loop's "before claim" snapshot): tier 0,
        // at the cap - peek falls through to `$chainEmails[$tier] ?? null`... which needs a
        // non-empty chain to pass the pre-filter, so use tier 0 with remindersSent under cap on
        // the first read instead, and only cross the cap on claim's own (3rd) read.
        $approval->method('getEscalationTier')->willReturn(0);
        $approval->method('getRemindersSent')->willReturnOnConsecutiveCalls(0, 0, 3);
        $approval->method('getAdminEmail')->willReturn('admin@example.com');
        $approval->method('getOrderId')->willReturn(7);
        $approval->expects(self::never())->method('setData');

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getEntityId')->willReturn(7);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->expects(self::once())->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('0 order approval escalations'));

        $this->makeCron()->execute();
    }

    /**
     * At the cap, with no further chain email configured past the current tier, the approval is
     * skipped entirely - same terminal "sits pending forever" outcome the original single-level
     * behavior had, now reached once the whole configured chain (however long) is exhausted.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenChainExhausted(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(true);
        $config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $config->method('getOrderApprovalEscalationMaxRemindersPerTier')->willReturn(3);
        $config->method('getOrderApprovalEscalationChainEmails')->willReturn(['tier1@example.com']);
        $this->config = $config;

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(3);
        $approval->method('getEscalationTier')->willReturn(1);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $this->orderCollectionFactory->expects(self::never())->method('create');
        $this->orderApprovalResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('0 order approval escalations'));

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsEscalationAndIncrementsCounter(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $approval->method('getToken')->willReturn('tok');
        $approval->method('getAdminEmail')->willReturn('admin@example.com');
        $approval->expects(self::once())->method('setData')->with('reminders_sent', 1);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getGrandTotal')->willReturn(150.0);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getStore')->willReturn($store);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $links = $this->createStub(OrderApprovalDecisionLinksInterface::class);
        $links->method('getApproveUrl')->willReturn('https://example.com/ordo/approval/approve/token/tok');
        $links->method('getRejectUrl')->willReturn('https://example.com/ordo/approval/reject/token/tok');

        $management = $this->createMock(OrderApprovalManagement::class);
        $management->expects(self::once())->method('buildDecisionLinks')
            ->with(self::identicalTo($store), 'tok')->willReturn($links);
        $this->orderApprovalManagement = $management;

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::once())->method('send')->with(
            'ordo_order_approval_escalation',
            [
                'order_increment_id' => '000000007',
                'order_total' => 150.0,
                'approve_url' => 'https://example.com/ordo/approval/approve/token/tok',
                'reject_url' => 'https://example.com/ordo/approval/reject/token/tok',
            ],
            'admin@example.com',
            '',
            self::identicalTo($store)
        );
        $this->reminderEmailSender = $sender;

        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('1 order approval escalations'));

        $triggerOutcomeLogger = $this->createMock(TriggerOutcomeLogger::class);
        $triggerOutcomeLogger->expects(self::once())->method('logSent')
            ->with(TriggerOutcomeLogger::TRIGGER_ORDER_APPROVAL, 42);
        $this->triggerOutcomeLogger = $triggerOutcomeLogger;

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenOrderNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(999);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    /**
     * The counter is claimed (incremented + saved) BEFORE the send attempt, not after - a crash
     * between a successful send and this save must never cause a duplicate escalation next tick.
     * When the send then genuinely fails, the counter must be rolled back to its pre-claim value
     * and saved again, so this approval is retried next run instead of quietly losing an
     * escalation attempt it never actually sent.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenEmailSendingThrows(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $setDataCalls = [];
        $approval->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::exactly(2))->method('save')->with($approval);
        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();

        self::assertSame([['reminders_sent', 1], ['reminders_sent', 0]], $setDataCalls);
    }
}
