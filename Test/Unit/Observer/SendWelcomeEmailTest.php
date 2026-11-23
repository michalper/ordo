<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Observer\SendWelcomeEmail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendWelcomeEmailTest extends TestCase
{
    public function testExecuteSkipsWhenLifecycleEmailsDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::never())->method('send');

        $observer = $this->createStub(EventObserver::class);

        $this->makeObserver($config, $tagManager, $sender)->execute($observer);
    }

    public function testExecuteTagsAndSendsEmail(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $event = new Event(['customer' => $customer]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('addTag')->with(42, SendWelcomeEmail::TAG_NEW_CUSTOMER);

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::once())->method('send')
            ->with('ordo_welcome_email', ['customer_name' => 'Jan'], 'jan@example.com', 'Jan');

        $this->makeObserver($config, $tagManager, $sender)->execute($observer);
    }

    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $event = new Event([]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::never())->method('send');

        $this->makeObserver($config, $tagManager, $sender)->execute($observer);
    }

    public function testExecuteLogsErrorWhenSendingThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $event = new Event(['customer' => $customer]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $sender = $this->createStub(ReminderEmailSender::class);
        $sender->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $observerInstance = new SendWelcomeEmail(
            $config,
            $this->createStub(CustomerTagManager::class),
            $sender,
            $logger
        );

        $observerInstance->execute($observer);
    }

    /**
     * Regression: addTag() is check-then-insert with no lock, so a genuinely overlapping
     * registration-success dispatch for the same customer could hit the unique (customer_id,
     * tag) constraint and throw. That must not crash the observer (which could otherwise fail
     * the native registration request itself) and must not block the welcome email that follows.
     */
    public function testExecuteStillSendsEmailAndLogsSeparatelyWhenTaggingThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $event = new Event(['customer' => $customer]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $tagManager = $this->createStub(CustomerTagManager::class);
        $tagManager->method('addTag')->willThrowException(new \RuntimeException('duplicate entry'));

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::once())->method('send')
            ->with('ordo_welcome_email', ['customer_name' => 'Jan'], 'jan@example.com', 'Jan');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('failed to tag'));

        $observerInstance = new SendWelcomeEmail($config, $tagManager, $sender, $logger);

        $observerInstance->execute($observer);
    }

    private function makeObserver(
        Config $config,
        CustomerTagManager $tagManager,
        ReminderEmailSender $sender
    ): SendWelcomeEmail {
        return new SendWelcomeEmail(
            $config,
            $tagManager,
            $sender,
            $this->createStub(LoggerInterface::class)
        );
    }
}
