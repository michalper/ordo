<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendPush;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendPushTest extends TestCase
{
    private PushSubscriptionManager $pushSubscriptionManager;
    private PushSender $pushSender;
    private Config $config;
    private MessageLogWriter $messageLogWriter;
    private ConsentManager $consentManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->pushSender = $this->createMock(PushSender::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->consentManager = $this->createStub(ConsentManager::class);
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeAction(): SendPush
    {
        return new SendPush(
            $this->pushSubscriptionManager,
            $this->pushSender,
            $this->config,
            $this->messageLogWriter,
            $this->consentManager,
            new SendRetrier(1),
            $this->logger
        );
    }

    private function subscription(string $endpoint): PushSubscription
    {
        $subscription = $this->createStub(PushSubscription::class);
        $subscription->method('getEndpoint')->willReturn($endpoint);
        return $subscription;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsToEverySubscription(): void
    {
        $subscriptionA = $this->subscription('https://push.example.com/a');
        $subscriptionB = $this->subscription('https://push.example.com/b');
        $this->pushSubscriptionManager->expects(self::once())->method('getForCustomer')->with(42)
            ->willReturn([$subscriptionA, $subscriptionB]);

        $this->pushSender->expects(self::exactly(2))->method('send');
        $this->messageLogWriter->expects(self::exactly(2))->method('recordSent')
            ->with('push', 42, self::anything(), null);

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Order shipped', 'body' => 'On its way', 'url' => 'https://example.com/order/1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdMissing(): void
    {
        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsQuietlyWhenPushDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isPushEnabled')->willReturn(false);

        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->logger->expects(self::once())->method('debug');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTitleMissing(): void
    {
        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsOptedOutWhenConsentWithdrawn(): void
    {
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->consentManager->expects(self::once())->method('hasConsent')
            ->with(42, ConsentChannel::Push)->willReturn(false);
        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->pushSender->expects(self::never())->method('send');
        $this->messageLogWriter->expects(self::once())->method('recordOptedOut')->with('push', 42, '');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNoSubscriptionsRegistered(): void
    {
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([]);
        $this->pushSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    /**
     * Regression test: a gone/dead subscription is permanently invalid, so it must fail fast on
     * the first attempt, not burn through every retry on an outcome that can never change.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesSubscriptionAndRecordsFailedWhenGone(): void
    {
        $subscription = $this->subscription('https://push.example.com/dead');
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([$subscription]);
        $this->pushSender->expects(self::once())->method('send')
            ->willThrowException(new SubscriptionGoneException('gone'));

        $this->pushSubscriptionManager->expects(self::once())->method('delete')->with($subscription);
        $this->messageLogWriter->expects(self::once())->method('recordFailed')->with('push', 42, 'https://push.example.com/dead');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRecordsFailedOnOtherSendFailure(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([$subscription]);
        $this->pushSender->expects(self::exactly(3))->method('send')
            ->willThrowException(new \RuntimeException('push service down'));

        $this->pushSubscriptionManager->expects(self::never())->method('delete');
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')->with('push', 42, 'https://push.example.com/a');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);

        self::assertTrue(true, 'execute() must not rethrow');
    }

    /**
     * Regression test for the retry/backoff fix: a transient failure on the first attempt(s)
     * must not permanently drop the message - a later attempt succeeding must still record the
     * message as sent, not failed.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRetriesATransientSendFailureAndSucceeds(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([$subscription]);
        $this->pushSender->expects(self::exactly(2))->method('send')->willReturnCallback(
            function () {
                static $calls = 0;
                $calls++;
                if ($calls < 2) {
                    throw new \RuntimeException('transient push service timeout');
                }
            }
        );

        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('push', 42, 'https://push.example.com/a', null);
        $this->messageLogWriter->expects(self::never())->method('recordFailed');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteContinuesToOtherSubscriptionsWhenOneFails(): void
    {
        $failing = $this->subscription('https://push.example.com/fail');
        $succeeding = $this->subscription('https://push.example.com/ok');
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([$failing, $succeeding]);
        $this->pushSender->method('send')->willReturnCallback(function ($subscription) use ($failing) {
            if ($subscription === $failing) {
                throw new \RuntimeException('down');
            }
        });

        $this->messageLogWriter->expects(self::once())->method('recordFailed');
        $this->messageLogWriter->expects(self::once())->method('recordSent');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }
}
