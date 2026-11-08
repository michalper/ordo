<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendGa4Event;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SendGa4EventTest extends TestCase
{
    private Config $config;
    private OrderRepositoryInterface $orderRepository;
    private JsonApiClient $jsonApiClient;
    private OutboundRateLimiter $rateLimiter;
    private MessageSendRetryQueue $messageSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isGa4Enabled')->willReturn(true);
        $this->config->method('getGa4MeasurementId')->willReturn('G-ABC123');
        $this->config->method('getGa4ApiSecret')->willReturn('a-real-secret');
        $this->config->method('getWebhookMaxRequestsPerSecond')->willReturn(0);

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->jsonApiClient = $this->createMock(JsonApiClient::class);
        $this->rateLimiter = $this->createStub(OutboundRateLimiter::class);
        $this->messageSendRetryQueue = $this->createMock(MessageSendRetryQueue::class);
        $this->messageSendRetryQueue->method('enqueueOrRethrow')->willReturnCallback(
            function (string $type, array $context, array $params, \Throwable $e): void {
                if (!empty($context[MessageSendRetryQueue::RETRY_CONTEXT_FLAG])) {
                    throw $e;
                }
                $this->messageSendRetryQueue->enqueue($type, $context, $params, $e);
            }
        );
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeOrder(): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getGrandTotal')->willReturn(99.95);
        $order->method('getOrderCurrencyCode')->willReturn('USD');

        return $order;
    }

    private function makeAction(): SendGa4Event
    {
        return new SendGa4Event(
            $this->config,
            $this->orderRepository,
            $this->jsonApiClient,
            $this->rateLimiter,
            new SendRetrier(1),
            $this->messageSendRetryQueue,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendsPurchaseEventWithOrderDataAndQueryStringAuth(): void
    {
        $this->orderRepository->method('get')->with(55)->willReturn($this->makeOrder());

        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://www.google-analytics.com/mp/collect?measurement_id=G-ABC123&api_secret=a-real-secret',
                self::callback(static function (array $payload): bool {
                    $event = $payload['events'][0];
                    return $payload['client_id'] === 'ordo-customer-42'
                        && $event['name'] === 'purchase'
                        && $event['params']['transaction_id'] === '100000123'
                        && $event['params']['value'] === 99.95
                        && $event['params']['currency'] === 'USD';
                }),
                [],
                'GA4 Measurement Protocol'
            )
            ->willReturn([]);

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEventNameParamOverridesDefault(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                self::anything(),
                self::callback(static fn (array $p): bool => $p['events'][0]['name'] === 'refund'),
                self::anything(),
                self::anything()
            )
            ->willReturn([]);

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, ['event_name' => 'refund']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isGa4Enabled')->willReturn(false);
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenMeasurementIdOrApiSecretMissing(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isGa4Enabled')->willReturn(true);
        $this->config->method('getGa4MeasurementId')->willReturn('');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenOrderIdMissingFromContext(): void
    {
        $this->orderRepository->expects(self::never())->method('get');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenOrderCannotBeLoaded(): void
    {
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 999, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailureEnqueuesRetryInsteadOfThrowing(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::once())
            ->method('enqueue')
            ->with(
                'send_ga4_event',
                self::isArray(),
                self::isArray(),
                self::isInstanceOf(RuntimeException::class)
            );

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsEventName(): void
    {
        self::assertSame(['event_name'], array_column($this->makeAction()->getParamFields(), 'name'));
    }
}
