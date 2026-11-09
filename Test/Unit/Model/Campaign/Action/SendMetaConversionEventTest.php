<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\PiiHasher;
use Ordo\Automation\Model\Campaign\Action\OrderContextResolver;
use Ordo\Automation\Model\Campaign\Action\SendMetaConversionEvent;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SendMetaConversionEventTest extends TestCase
{
    private Config $config;
    private CustomerRepositoryInterface $customerRepository;
    private OrderRepositoryInterface $orderRepository;
    private OrderContextResolver $orderContextResolver;
    private PiiHasher $piiHasher;
    private JsonApiClient $jsonApiClient;
    private OutboundRateLimiter $rateLimiter;
    private MessageSendRetryQueue $messageSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isMetaCapiEnabled')->willReturn(true);
        $this->config->method('getMetaCapiPixelId')->willReturn('1234567890');
        $this->config->method('getMetaCapiAccessToken')->willReturn('a-real-token');
        $this->config->method('getWebhookMaxRequestsPerSecond')->willReturn(0);

        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderContextResolver = new OrderContextResolver($this->orderRepository);
        $this->piiHasher = new PiiHasher();
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
        $order->method('getGrandTotal')->willReturn(99.95);
        $order->method('getOrderCurrencyCode')->willReturn('USD');

        return $order;
    }

    private function makeCustomer(string $email): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);

        return $customer;
    }

    private function makeAction(): SendMetaConversionEvent
    {
        return new SendMetaConversionEvent(
            $this->config,
            $this->customerRepository,
            $this->orderContextResolver,
            $this->piiHasher,
            $this->jsonApiClient,
            $this->rateLimiter,
            new SendRetrier(1),
            $this->messageSendRetryQueue,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendsPurchaseEventWithHashedEmailAndOrderData(): void
    {
        $this->orderRepository->method('get')->with(55)->willReturn($this->makeOrder());
        $this->customerRepository->method('getById')->with(42)->willReturn($this->makeCustomer('Jane.Doe@Example.com'));

        $expectedHash = hash('sha256', 'jane.doe@example.com');

        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://graph.facebook.com/v21.0/1234567890/events?access_token=a-real-token',
                self::callback(static function (array $payload) use ($expectedHash): bool {
                    $event = $payload['data'][0];
                    return $event['event_name'] === 'Purchase'
                        && $event['action_source'] === 'website'
                        && $event['user_data']['em'] === [$expectedHash]
                        && $event['custom_data']['value'] === 99.95
                        && $event['custom_data']['currency'] === 'usd';
                }),
                [],
                'Meta Conversions API'
            )
            ->willReturn([]);

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEventNameParamOverridesDefault(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->customerRepository->method('getById')->willReturn($this->makeCustomer('a@example.com'));
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                self::anything(),
                self::callback(static fn (array $p): bool => $p['data'][0]['event_name'] === 'InitiateCheckout'),
                self::anything(),
                self::anything()
            )
            ->willReturn([]);

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, ['event_name' => 'InitiateCheckout']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isMetaCapiEnabled')->willReturn(false);
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenPixelIdOrAccessTokenMissing(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isMetaCapiEnabled')->willReturn(true);
        $this->config->method('getMetaCapiPixelId')->willReturn('');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 55, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenOrderCannotBeLoaded(): void
    {
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->customerRepository->expects(self::never())->method('getById');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 999, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenCustomerCannotBeLoaded(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['order_id' => 55, 'customer_id' => 999];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailureEnqueuesRetryInsteadOfThrowing(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->customerRepository->method('getById')->willReturn($this->makeCustomer('a@example.com'));
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::once())
            ->method('enqueue')
            ->with(
                'send_meta_conversion_event',
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
