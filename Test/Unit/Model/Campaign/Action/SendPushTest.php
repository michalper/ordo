<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendPush;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendPushTest extends TestCase
{
    private PushSubscriptionManager $pushSubscriptionManager;
    private PushSubscriptionSender $pushSubscriptionSender;
    private Config $config;
    private MessageLogWriter $messageLogWriter;
    private ConsentManager $consentManager;
    private QuietHoursGate $quietHoursGate;
    private FrequencyCapGate $frequencyCapGate;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->pushSubscriptionSender = $this->createMock(PushSubscriptionSender::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->consentManager = $this->createStub(ConsentManager::class);
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->quietHoursGate = $this->createStub(QuietHoursGate::class);
        $this->quietHoursGate->method('allows')->willReturn(true);
        $this->frequencyCapGate = $this->createStub(FrequencyCapGate::class);
        $this->frequencyCapGate->method('allows')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeAction(): SendPush
    {
        return new SendPush(
            $this->pushSubscriptionManager,
            $this->pushSubscriptionSender,
            $this->config,
            $this->messageLogWriter,
            $this->consentManager,
            $this->quietHoursGate,
            $this->frequencyCapGate,
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

        $this->pushSubscriptionSender->expects(self::exactly(2))->method('send')
            ->with(self::anything(), self::anything(), 42, null, null);

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Order shipped', 'body' => 'On its way', 'url' => 'https://example.com/order/1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSubstitutesRecommendedProductsTextTokenIntoBody(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([$subscription]);

        $this->pushSubscriptionSender->expects(self::once())->method('send')->with(
            $subscription,
            self::callback(function (string $payload) {
                $decoded = json_decode($payload, true);
                return $decoded['body'] === 'Check out: Widget - $19.99';
            }),
            42,
            null,
            null
        );

        $context = ['customer_id' => 42, 'recommended_products_text' => 'Check out: Widget - $19.99'];
        $this->makeAction()->execute($context, ['title' => 'For you', 'body' => '{{recommended_products_text}}']);
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
        $this->pushSubscriptionSender->expects(self::never())->method('send');
        $this->messageLogWriter->expects(self::once())->method('recordOptedOut')->with('push', 42, '');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenQuietHoursGateDefers(): void
    {
        $this->quietHoursGate = $this->createMock(QuietHoursGate::class);
        $this->quietHoursGate->expects(self::once())->method('allows')->willReturn(false);
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::never())->method('allows');
        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->pushSubscriptionSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsSuppressedWhenFrequencyCapReached(): void
    {
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::once())->method('allows')
            ->with(42, 'push', '', 'send_push')->willReturn(false);
        $this->pushSubscriptionManager->expects(self::never())->method('getForCustomer');
        $this->pushSubscriptionSender->expects(self::never())->method('send');
        $this->messageLogWriter->expects(self::never())->method('recordSuppressed');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNoSubscriptionsRegistered(): void
    {
        $this->pushSubscriptionManager->method('getForCustomer')->willReturn([]);
        $this->pushSubscriptionSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['title' => 'Hi']);
    }
}
