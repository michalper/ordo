<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendSms;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\Sms\OptedOutException;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendSmsTest extends TestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private SmsSenderInterface $smsSender;
    private Config $config;
    private MessageLogWriter $messageLogWriter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->smsSender = $this->createMock(SmsSenderInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('isSmsEnabled')->willReturn(true);
    }

    private function makeAction(): SendSms
    {
        return new SendSms(
            $this->customerRepository,
            $this->smsSender,
            $this->config,
            $this->messageLogWriter,
            $this->logger
        );
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
    public function testExecuteSendsSms(): void
    {
        $this->customerRepository->method('getById')->willReturnMap([[42, $this->customerWithPhone('+15551234567')]]);
        $this->smsSender->expects(self::once())->method('send')->with('+15551234567', 'hello')->willReturn('SM123');
        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('sms', 42, '+15551234567', 'SM123');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdMissing(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdIsZero(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 0];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdIsNegative(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => -5];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsQuietlyWhenSmsDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isSmsEnabled')->willReturn(false);

        $this->customerRepository->expects(self::never())->method('getById');
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('debug');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSilentlyWhenCustomerNotFound(): void
    {
        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('no such customer')));
        $this->smsSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenPhoneAttributeMissing(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone(null));
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenPhoneAttributeEmpty(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('   '));
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenMessageMissing(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenMessageEmpty(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->smsSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => '   ']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSenderThrowsAndDoesNotRethrow(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->smsSender->method('send')->willThrowException(new \RuntimeException('twilio down'));
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')->with('sms', 42, '+15551234567');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);

        self::assertTrue(true, 'execute() must not rethrow');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsInfoAndRecordsOptedOutWhenSenderThrowsOptedOutException(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->smsSender->method('send')->willThrowException(new OptedOutException('+15551234567 has opted out of SMS.'));
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('info');
        $this->messageLogWriter->expects(self::once())->method('recordOptedOut')->with('sms', 42, '+15551234567');
        $this->messageLogWriter->expects(self::never())->method('recordFailed');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['message' => 'hello']);
    }
}
