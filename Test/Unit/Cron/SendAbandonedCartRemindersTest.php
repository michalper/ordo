<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendAbandonedCartReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\Email\MessageIdGenerator;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendAbandonedCartRemindersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();

        return $select;
    }

    /**
     * ReminderLogStore::claimUnderLimit() wraps its count-check in GET_LOCK()/RELEASE_LOCK() - a
     * raw SQL string first arg to fetchOne(), unlike the count query's own Select object - so the
     * generic AdapterInterface mock these tests share needs to answer both distinctly. $countResult
     * is what the plain insert() this class used to call never needed to care about: 0 = fewer
     * than the configured max reminders already logged for this quote (claim proceeds to insert).
     */
    private function stubFetchOne(AdapterInterface $connection, int $countResult): void
    {
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $countResult
        );
    }

    private function makeConsentManager(bool $hasConsent = true): ConsentManager
    {
        $consentManager = $this->createStub(ConsentManager::class);
        $consentManager->method('hasTopicConsentForCustomers')->willReturnCallback(
            fn (array $customerIds) => array_fill_keys($customerIds, $hasConsent)
        );

        return $consentManager;
    }

    /**
     * fetchVisibleItemsByQuoteId() must not run its own query at all when there are no quotes to
     * batch-load items for - an empty IN (...) clause would be nonsensical.
     */
    public function testExecuteSkipsTheItemBatchLookupWhenNoQuotesAreDue(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::once())->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 abandoned cart reminders'));

        $this->makeCron($config, $resourceConnection, null, $logger)->execute();
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsReminderAndDispatchesCampaignForKnownCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            []
        );
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('cart_abandoned', [
            'customer_id' => 5,
            'cart_subtotal' => 150.0,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 abandoned cart reminders'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    /**
     * The batch item-lookup (fetchVisibleItemsByQuoteId()) replaced a per-row Quote::load() -
     * this proves the batch result actually reaches the email template, keyed by the right
     * quote_id, not just that the method runs without error.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteIncludesTheBatchLoadedCartItemsInTheReminderEmail(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            [
                ['quote_id' => 10, 'name' => 'Widget', 'qty' => 2.0],
                ['quote_id' => 10, 'name' => 'Gadget', 'qty' => 1.0],
            ]
        );
        $this->stubFetchOne($connection, 0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateVars')
            ->with(self::callback(function (array $vars): bool {
                self::assertSame(
                    [
                        ['name' => 'Widget', 'qty' => 2.0],
                        ['name' => 'Gadget', 'qty' => 1.0],
                    ],
                    $vars['cart_items']
                );

                return true;
            }))
            ->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            new ReminderEmailSender(
                $transportBuilder,
                $this->makeStoreManagerStub(),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            $this->createStub(CampaignDispatcher::class),
            $this->makeConsentManager(),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(MessageLogWriter::class),
            new ReminderLogStore($resourceConnection)
        ))->execute();
    }

    /**
     * Closes ROADMAP.md's "Cross-channel fallback for cart abandonment" own wiring: a registered
     * customer's fixed reminder is logged to ordo_message_log with a real Message-ID (the same
     * open-tracking correlation Model\Campaign\Action\SendEmail already uses), and the resulting
     * message_log_id is written back onto this reminder's own log row, so Cron\
     * SendAbandonedCartFallbackReminders can later check whether it was ever opened.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsTheFixedReminderAndStoresItsMessageLogId(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            []
        );
        // claimUnderLimit()'s own count check (0 = fewer than the configured max reminders
        // already logged, claim proceeds) - MessageLogWriter::recordSent() hands the saved row's
        // own id straight back now, so there's no second fetchOne() lookup left to stub.
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('update')->with(
            'ordo_abandoned_cart_reminder_log',
            ['message_log_id' => 77],
            self::anything()
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $messageIdGenerator = $this->createStub(MessageIdGenerator::class);
        $messageIdGenerator->method('generate')->willReturn('abc123@example.test');

        $messageLogWriter = $this->createMock(MessageLogWriter::class);
        $messageLogWriter->expects(self::once())->method('recordSent')->with(
            'email',
            5,
            'jan@example.com',
            '<abc123@example.test>'
        )->willReturn(77);

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            new ReminderEmailSender(
                $this->makeTransportBuilderStub(),
                $this->makeStoreManagerStub(),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            $this->createStub(CampaignDispatcher::class),
            $this->makeConsentManager(),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $messageIdGenerator,
            $messageLogWriter,
            new ReminderLogStore($resourceConnection)
        ))->execute();
    }

    /**
     * A registered customer who withdrew email consent must never receive the fixed reminder
     * email (sent directly via TransportBuilder, bypassing the campaign engine's own per-action
     * consent gate). The cart_abandoned campaign trigger still dispatches regardless - its own
     * channel actions (send_email/send_sms/...) check consent themselves before sending, and a
     * non-channel action (add_tag, generate_coupon) has nothing to do with email consent, so
     * suppressing the whole trigger here would silently block those too. (This used to be a
     * regression test for the opposite bug - no consent check existed at all; a later audit found
     * that the fix that closed it had swung too far the other way and blocked the trigger
     * entirely, not just the email.)
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsOnlyTheFixedEmailWhenRegisteredCustomerWithdrewEmailConsent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            []
        );
        // The claim still happens - the campaign trigger below still needs the same
        // claim-before-dispatch dedup/cap every other reminder cron relies on.
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('cart_abandoned', [
            'customer_id' => 5,
            'cart_subtotal' => 150.0,
        ]);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('hasTopicConsentForCustomers')->with([5], ConsentChannel::Email, ConsentTopic::Promotional)->willReturn([5 => false]);

        // The fixed email path starts with MessageIdGenerator::generate() - asserting it's never
        // called proves sendReminder() itself was skipped, without needing to mock every
        // TransportBuilder call to prove no email assembly happened.
        $messageIdGenerator = $this->createMock(MessageIdGenerator::class);
        $messageIdGenerator->expects(self::never())->method('generate');

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->makeStoreManagerStub(),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            $dispatcher,
            $consentManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $messageIdGenerator,
            $this->createStub(MessageLogWriter::class),
            new ReminderLogStore($resourceConnection)
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCampaignDispatchForGuestQuote(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 11,
                    'customer_id' => null,
                    'customer_email' => 'guest@example.com',
                    'customer_firstname' => null,
                    'subtotal' => 60.0,
                    'store_id' => 1,
                ],
            ],
            []
        );
        // Without this, claimUnderLimit()'s own GET_LOCK() fetchOne() call returns the mock's
        // unconfigured default (null), so the row is never claimed at all and sendReminder()/
        // dispatchCampaigns() are never reached - this test would then "pass" for the wrong
        // reason (dispatch() never called because nothing ran, not because the guest-specific
        // skip logic in either method actually executed).
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingReminderThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            []
        );

        $this->stubFetchOne($connection, 0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        // ReminderEmailSender::getStore() is the first thing sendReminder() does after building
        // the message id - throwing here exercises the same claim-rollback/logFailure path a real
        // send failure would, without needing a TransportBuilder mock at all.
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $storeManager,
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            $dispatcher,
            $this->makeConsentManager(),
            $this->makeCronRunLogger($logger),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(MessageLogWriter::class),
            new ReminderLogStore($resourceConnection)
        ))->execute();
    }

    /**
     * `claimUnderLimit()` re-checks "reminders already logged < maxReminders" atomically under
     * its own lock - when a quote is already at the configured limit (e.g. a second cron tick
     * racing the first), it returns false and this row must be skipped entirely: no email sent,
     * no campaign dispatched.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAQuoteAlreadyAtTheMaxReminderLimit(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                [
                    'entity_id' => 10,
                    'customer_id' => 5,
                    'customer_email' => 'jan@example.com',
                    'customer_firstname' => 'Jan',
                    'subtotal' => 150.0,
                    'store_id' => 1,
                ],
            ],
            []
        );
        // 1 already-logged reminder >= the configured max of 1 - claimUnderLimit() must refuse.
        $this->stubFetchOne($connection, 1);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $dispatcher)->execute();
    }

    private function makeStoreManagerStub(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function makeTransportBuilderStub(): TransportBuilder
    {
        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        return $transportBuilder;
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): SendAbandonedCartReminders {
        return new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            new ReminderEmailSender(
                $this->makeTransportBuilderStub(),
                $this->makeStoreManagerStub(),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $this->makeConsentManager(),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class)),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(MessageLogWriter::class),
            new ReminderLogStore($resourceConnection)
        );
    }
}
