<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\SendAbandonedCartFallbackReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendSms;
use Ordo\Automation\Model\Campaign\Action\SendWhatsApp;
use Ordo\Automation\Model\Config\Source\AbandonedCartFallbackChannel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendAbandonedCartFallbackRemindersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    private function makeConfig(
        bool $enabled,
        int $delayHours = 24,
        string $channel = AbandonedCartFallbackChannel::SMS,
        int $whatsAppTemplateId = 0
    ): Config {
        $config = $this->createStub(Config::class);
        $config->method('isAbandonedCartFallbackEnabled')->willReturn($enabled);
        $config->method('getAbandonedCartFallbackDelayHours')->willReturn($delayHours);
        $config->method('getAbandonedCartFallbackChannel')->willReturn($channel);
        $config->method('getAbandonedCartFallbackWhatsAppTemplateId')->willReturn($whatsAppTemplateId);

        return $config;
    }

    public function testExecuteDoesNothingWhenDisabled(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $cron = new SendAbandonedCartFallbackReminders(
            $this->makeConfig(enabled: false),
            $this->makeResourceConnection($connection),
            $this->createStub(SendSms::class),
            $this->createStub(SendWhatsApp::class),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsSmsFallbackForAnUnopenedReminderAndClaimsIt(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'log_entity_id' => 42,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);
        $connection->expects(self::once())->method('update')->with(
            'ordo_abandoned_cart_reminder_log',
            self::callback(static fn (array $data): bool => array_key_exists('fallback_sent_at', $data)),
            self::anything()
        );

        $sendSms = $this->createMock(SendSms::class);
        $sendSms->expects(self::once())->method('execute')->with(
            ['customer_id' => 5],
            self::callback(static fn (array $params): bool => isset($params['message']))
        );

        $sendWhatsApp = $this->createMock(SendWhatsApp::class);
        $sendWhatsApp->expects(self::never())->method('execute');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('attempted 1 cross-channel abandoned-cart fallback(s) via sms')
        );

        $cron = new SendAbandonedCartFallbackReminders(
            $this->makeConfig(enabled: true, channel: AbandonedCartFallbackChannel::SMS),
            $this->makeResourceConnection($connection),
            $sendSms,
            $sendWhatsApp,
            $this->makeCronRunLogger($logger)
        );

        $cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsWhatsAppFallbackWhenChannelIsWhatsAppAndTemplateConfigured(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'log_entity_id' => 42,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);

        $sendSms = $this->createMock(SendSms::class);
        $sendSms->expects(self::never())->method('execute');

        $sendWhatsApp = $this->createMock(SendWhatsApp::class);
        $sendWhatsApp->expects(self::once())->method('execute')->with(
            ['customer_id' => 5],
            ['template_id' => '9']
        );

        $cron = new SendAbandonedCartFallbackReminders(
            $this->makeConfig(
                enabled: true,
                channel: AbandonedCartFallbackChannel::WHATSAPP,
                whatsAppTemplateId: 9
            ),
            $this->makeResourceConnection($connection),
            $sendSms,
            $sendWhatsApp,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhatsAppFallbackWhenNoTemplateIsConfigured(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'log_entity_id' => 42,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);

        $sendSms = $this->createMock(SendSms::class);
        $sendSms->expects(self::never())->method('execute');

        $sendWhatsApp = $this->createMock(SendWhatsApp::class);
        $sendWhatsApp->expects(self::never())->method('execute');

        $cron = new SendAbandonedCartFallbackReminders(
            $this->makeConfig(
                enabled: true,
                channel: AbandonedCartFallbackChannel::WHATSAPP,
                whatsAppTemplateId: 0
            ),
            $this->makeResourceConnection($connection),
            $sendSms,
            $sendWhatsApp,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );

        $cron->execute();
    }

    public function testExecuteLogsZeroAttemptsWhenNothingIsDue(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->expects(self::never())->method('update');

        $sendSms = $this->createMock(SendSms::class);
        $sendSms->expects(self::never())->method('execute');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('attempted 0 cross-channel abandoned-cart fallback(s)')
        );

        $cron = new SendAbandonedCartFallbackReminders(
            $this->makeConfig(enabled: true),
            $this->makeResourceConnection($connection),
            $sendSms,
            $this->createStub(SendWhatsApp::class),
            $this->makeCronRunLogger($logger)
        );

        $cron->execute();
    }
}
