<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ReorderCycle;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycle\OptedOutException;
use Ordo\Automation\Model\ReorderCycle\ReorderReminderSender;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ReorderReminderSenderTest extends TestCase
{
    private ReminderEmailSender $emailSender;
    private ReminderLogStore $reminderLogStore;
    private SalesRepEmailContext $salesRepEmailContext;
    private ConsentManager $consentManager;
    private TriggerOutcomeLogger $triggerOutcomeLogger;
    private ReorderReminderSender $sender;

    protected function setUp(): void
    {
        $this->emailSender = $this->createMock(ReminderEmailSender::class);
        $this->reminderLogStore = $this->createMock(ReminderLogStore::class);
        $this->salesRepEmailContext = $this->createStub(SalesRepEmailContext::class);
        $this->salesRepEmailContext->method('getForLoadedCustomer')->willReturn([]);
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->triggerOutcomeLogger = $this->createMock(TriggerOutcomeLogger::class);

        $this->sender = new ReorderReminderSender(
            $this->emailSender,
            $this->reminderLogStore,
            $this->salesRepEmailContext,
            $this->consentManager,
            $this->triggerOutcomeLogger
        );
    }

    private function makeCycle(int $entityId = 5): ReorderCycle
    {
        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn($entityId);
        $cycle->method('getSku')->willReturn('24-MB01');
        $cycle->method('getAvgIntervalDays')->willReturn(30);

        return $cycle;
    }

    private function makeCustomer(int $id = 7): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn('jane@example.com');
        $customer->method('getFirstname')->willReturn('Jane');

        return $customer;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendNowThrowsOptedOutExceptionWithoutClaimingOrSendingWhenNoConsent(): void
    {
        $this->consentManager->method('hasConsent')->with(7, ConsentChannel::Email)->willReturn(false);

        $this->reminderLogStore->expects(self::never())->method('insert');
        $this->emailSender->expects(self::never())->method('send');

        $this->expectException(OptedOutException::class);
        $this->sender->sendNow($this->makeCycle(), $this->makeCustomer());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendNowClaimsThenSendsThenLogsOutcome(): void
    {
        $this->consentManager->method('hasConsent')->willReturn(true);

        $this->reminderLogStore->expects(self::once())->method('insert')
            ->with('ordo_reorder_reminder_log', self::callback(
                fn (array $row) => $row['reorder_cycle_id'] === 5 && isset($row['sent_at'])
            ));
        $this->reminderLogStore->expects(self::never())->method('deleteMatching');

        $this->emailSender->expects(self::once())->method('send')
            ->with(
                'ordo_reorder_reminder',
                self::callback(fn (array $vars) => $vars['sku'] === '24-MB01' && $vars['avg_interval_days'] === 30),
                'jane@example.com',
                'Jane'
            );

        $this->triggerOutcomeLogger->expects(self::once())->method('logSent')
            ->with(TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER, 7);

        $this->sender->sendNow($this->makeCycle(), $this->makeCustomer());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendNowRollsBackTheClaimAndRethrowsWhenSendFails(): void
    {
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->emailSender->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $this->reminderLogStore->expects(self::once())->method('insert');
        $this->reminderLogStore->expects(self::once())->method('deleteMatching')
            ->with('ordo_reorder_reminder_log', self::callback(fn (array $row) => $row['reorder_cycle_id'] === 5));
        $this->triggerOutcomeLogger->expects(self::never())->method('logSent');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('smtp down');
        $this->sender->sendNow($this->makeCycle(), $this->makeCustomer());
    }
}
