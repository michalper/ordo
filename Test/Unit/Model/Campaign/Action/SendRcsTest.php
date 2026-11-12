<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendRcs;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Rcs\RcsSenderInterface;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\PhoneNumberResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendRcsTest extends TestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private RcsSenderInterface $rcsSender;
    private Config $config;
    private MessageLogWriter $messageLogWriter;
    private ConsentManager $consentManager;
    private QuietHoursGate $quietHoursGate;
    private FrequencyCapGate $frequencyCapGate;
    private LoggerInterface $logger;
    private CustomerStoreResolver $customerStoreResolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->rcsSender = $this->createMock(RcsSenderInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->customerStoreResolver = $this->createStub(CustomerStoreResolver::class);
        $this->customerStoreResolver->method('resolveStoreId')->willReturn(null);
        $this->consentManager = $this->createStub(ConsentManager::class);
        $this->consentManager->method('hasTopicConsent')->willReturn(true);
        $this->quietHoursGate = $this->createStub(QuietHoursGate::class);
        $this->quietHoursGate->method('allows')->willReturn(true);
        $this->frequencyCapGate = $this->createStub(FrequencyCapGate::class);
        $this->frequencyCapGate->method('allows')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('isRcsEnabled')->willReturn(true);
    }

    private function makeAction(): SendRcs
    {
        return new SendRcs(
            $this->customerRepository,
            $this->rcsSender,
            $this->config,
            $this->messageLogWriter,
            $this->consentManager,
            $this->quietHoursGate,
            $this->frequencyCapGate,
            $this->logger,
            $this->customerStoreResolver,
            new PhoneNumberResolver()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsOptedOutWhenConsentWithdrawn(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->consentManager->expects(self::once())->method('hasTopicConsent')
            ->with(42, ConsentChannel::Rcs, ConsentTopic::Promotional)->willReturn(false);
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('info');
        $this->messageLogWriter->expects(self::once())->method('recordOptedOut')
            ->with('rcs', 42, '+15551234567');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenQuietHoursGateDefers(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->quietHoursGate = $this->createMock(QuietHoursGate::class);
        $this->quietHoursGate->expects(self::once())->method('allows')->willReturn(false);
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::never())->method('allows');
        $this->rcsSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsSuppressedWhenFrequencyCapReached(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::once())->method('allows')
            ->with(42, 'rcs', '+15551234567', 'send_rcs')->willReturn(false);
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::never())->method('info');
        $this->messageLogWriter->expects(self::never())->method('recordSuppressed');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    private function customerWithPhone(?string $phone): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        if ($phone === null) {
            $customer->method('getCustomAttribute')->willReturn(null);
        } else {
            $attribute = $this->createStub(AttributeInterface::class);
            $attribute->method('getValue')->willReturn($phone);
            $customer->method('getCustomAttribute')->willReturn($attribute);
        }

        return $customer;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsRcs(): void
    {
        $this->customerRepository->method('getById')->willReturnMap([[42, $this->customerWithPhone('+15551234567')]]);
        $this->rcsSender->expects(self::once())->method('send')->with('+15551234567', 'hello')->willReturn('SM123');
        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('rcs', 42, '+15551234567', 'SM123');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSubstitutesRecommendedProductsTextTokenIntoMessage(): void
    {
        $this->customerRepository->method('getById')->willReturnMap([[42, $this->customerWithPhone('+15551234567')]]);
        $this->rcsSender->expects(self::once())->method('send')
            ->with('+15551234567', 'Hi! Recommended for you: Widget - $19.99')
            ->willReturn('SM123');

        $context = ['customer_id' => 42, 'recommended_products_text' => 'Recommended for you: Widget - $19.99'];
        $this->makeAction()->execute($context, ['message' => 'Hi! {{recommended_products_text}}']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdMissing(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsQuietlyWhenRcsDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isRcsEnabled')->willReturn(false);
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));

        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('debug');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteChecksRcsEnabledForTheCustomersOwnStore(): void
    {
        $customer = $this->customerWithPhone('+15551234567');
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->customerStoreResolver = $this->createMock(CustomerStoreResolver::class);
        $this->customerStoreResolver->expects(self::once())->method('resolveStoreId')->with($customer)->willReturn(7);

        $this->config = $this->createMock(Config::class);
        $this->config->expects(self::once())->method('isRcsEnabled')->with(7)->willReturn(false);

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSilentlyWhenCustomerNotFound(): void
    {
        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('no such customer')));
        $this->rcsSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenPhoneAttributeMissing(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone(null));
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRecordsFailedWhenPhoneIsNotValidE164(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('0123456789'));
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')
            ->with('rcs', 42, '0123456789');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenMessageMissing(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->rcsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRecordsFailedWhenSenderThrows(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->rcsSender->expects(self::once())->method('send')
            ->willThrowException(new \RuntimeException('twilio down'));
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')->with('rcs', 42, '+15551234567');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);

        self::assertTrue(true, 'execute() must not rethrow');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsMessageAndTopic(): void
    {
        self::assertSame(
            ['message', 'topic'],
            array_column($this->makeAction()->getParamFields(), 'name')
        );
    }
}
