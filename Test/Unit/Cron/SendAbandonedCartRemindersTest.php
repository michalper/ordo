<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendAbandonedCartReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
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

    private function makeConsentManager(bool $hasConsent = true): ConsentManager
    {
        $consentManager = $this->createStub(ConsentManager::class);
        $consentManager->method('hasConsentForCustomers')->willReturnCallback(
            fn (array $customerIds) => array_fill_keys($customerIds, $hasConsent)
        );

        return $consentManager;
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
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);
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
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);
        // The real ordo_message_log row this test's own MessageLogWriter stub "wrote" - looked
        // up by provider_message_id right after, exactly as the real code does.
        $connection->method('fetchOne')->willReturn('77');
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
        );

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            $this->makeQuoteFactoryStub(),
            $this->makeTransportBuilderStub(),
            $this->makeStoreManagerStub(),
            $this->createStub(StateInterface::class),
            $this->createStub(CampaignDispatcher::class),
            $this->makeConsentManager(),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $messageIdGenerator,
            $this->createStub(PendingMessageIdHolder::class),
            $messageLogWriter
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
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);
        // The claim still happens - the campaign trigger below still needs the same
        // claim-before-dispatch dedup/cap every other reminder cron relies on.
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
        $consentManager->expects(self::once())->method('hasConsentForCustomers')->with([5], ConsentChannel::Email)->willReturn([5 => false]);

        // The fixed email path starts with quoteFactory->create()->load(...) - asserting it's
        // never called proves sendReminder() itself was skipped, without needing to mock every
        // TransportBuilder call to prove no email assembly happened.
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->expects(self::never())->method('create');

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            $quoteFactory,
            $this->createStub(TransportBuilder::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $dispatcher,
            $consentManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(PendingMessageIdHolder::class),
            $this->createStub(MessageLogWriter::class)
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
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 11,
                'customer_id' => null,
                'customer_email' => 'guest@example.com',
                'customer_firstname' => null,
                'subtotal' => 60.0,
            ],
        ]);

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
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $quoteFactory = $this->createStub(QuoteFactory::class);
        $quoteFactory->method('create')->willThrowException(new \RuntimeException('quote load failed'));

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            $quoteFactory,
            $this->createStub(TransportBuilder::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $dispatcher,
            $this->makeConsentManager(),
            $this->makeCronRunLogger($logger),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(PendingMessageIdHolder::class),
            $this->createStub(MessageLogWriter::class)
        ))->execute();
    }

    private function makeQuoteFactoryStub(): QuoteFactory
    {
        $quoteItem = $this->createStub(\Magento\Quote\Model\Quote\Item::class);
        $quoteItem->method('getName')->willReturn('Widget');
        $quoteItem->method('getQty')->willReturn(2.0);

        $quote = $this->createStub(Quote::class);
        $quote->method('load')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);

        $quoteFactory = $this->createStub(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);

        return $quoteFactory;
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
            $this->makeQuoteFactoryStub(),
            $this->makeTransportBuilderStub(),
            $this->makeStoreManagerStub(),
            $this->createStub(StateInterface::class),
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $this->makeConsentManager(),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class)),
            $this->createStub(MessageIdGenerator::class),
            $this->createStub(PendingMessageIdHolder::class),
            $this->createStub(MessageLogWriter::class)
        );
    }
}
