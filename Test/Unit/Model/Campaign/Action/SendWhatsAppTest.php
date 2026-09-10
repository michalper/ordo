<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Campaign\Action\SendWhatsApp;
use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\QuietHoursGate;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendWhatsAppTest extends TestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private WhatsAppSender $whatsAppSender;
    private Config $config;
    private WhatsAppTemplateFactory $whatsAppTemplateFactory;
    private WhatsAppTemplateResource $whatsAppTemplateResource;
    private MessageLogWriter $messageLogWriter;
    private ConsentManager $consentManager;
    private QuietHoursGate $quietHoursGate;
    private FrequencyCapGate $frequencyCapGate;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->whatsAppSender = $this->createMock(WhatsAppSender::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWhatsAppEnabled')->willReturn(true);
        $this->whatsAppTemplateFactory = $this->createMock(WhatsAppTemplateFactory::class);
        $this->whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->consentManager = $this->createStub(ConsentManager::class);
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->quietHoursGate = $this->createStub(QuietHoursGate::class);
        $this->quietHoursGate->method('allows')->willReturn(true);
        $this->frequencyCapGate = $this->createStub(FrequencyCapGate::class);
        $this->frequencyCapGate->method('allows')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeAction(): SendWhatsApp
    {
        return new SendWhatsApp(
            $this->customerRepository,
            $this->whatsAppSender,
            $this->config,
            $this->whatsAppTemplateFactory,
            $this->whatsAppTemplateResource,
            $this->messageLogWriter,
            $this->consentManager,
            $this->quietHoursGate,
            $this->frequencyCapGate,
            new SendRetrier(1),
            $this->logger
        );
    }

    private function makeApprovedTemplate(): WhatsAppTemplate
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $template = new WhatsAppTemplate(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $resource
        );
        $template->setId(3);
        $template->setMetaTemplateName('order_shipped_v1');
        $template->setLanguage('en_US');
        $template->setStatus(WhatsAppTemplate::STATUS_APPROVED);

        return $template;
    }

    private function stubApprovedTemplate(): void
    {
        $template = $this->makeApprovedTemplate();
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);
        $this->whatsAppTemplateResource->method('load')->willReturnCallback(
            function ($model) use ($template) {
                $model->setData($template->getData());
                return $this->whatsAppTemplateResource;
            }
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
    public function testExecuteSendsWhatsAppTemplateMessage(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->whatsAppSender->expects(self::once())->method('send')
            ->with('+15551234567', 'order_shipped_v1', 'en_US', ['John', 'ORD-1'])
            ->willReturn('wamid.123');
        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('whatsapp', 42, '+15551234567', 'wamid.123');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3', 'params' => 'John,ORD-1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsWithoutParamsWhenParamsFieldBlank(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->whatsAppSender->expects(self::once())->method('send')
            ->with('+15551234567', 'order_shipped_v1', 'en_US', [])
            ->willReturn('wamid.123');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdMissing(): void
    {
        $this->whatsAppTemplateFactory->expects(self::never())->method('create');
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsQuietlyWhenWhatsAppDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWhatsAppEnabled')->willReturn(false);

        $this->whatsAppTemplateFactory->expects(self::never())->method('create');
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('debug');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTemplateIdMissing(): void
    {
        $this->whatsAppTemplateFactory->expects(self::never())->method('create');
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTemplateNotFound(): void
    {
        $emptyTemplate = new WhatsAppTemplate(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $this->createStub(AbstractDb::class)
        );
        $this->whatsAppTemplateFactory->method('create')->willReturn($emptyTemplate);

        $this->customerRepository->expects(self::never())->method('getById');
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '999']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTemplateNotApproved(): void
    {
        $template = $this->makeApprovedTemplate();
        $template->setStatus(WhatsAppTemplate::STATUS_PENDING);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);
        $this->whatsAppTemplateResource->method('load')->willReturnCallback(
            function ($model) use ($template) {
                $model->setData($template->getData());
                return $this->whatsAppTemplateResource;
            }
        );

        $this->customerRepository->expects(self::never())->method('getById');
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSilentlyWhenCustomerNotFound(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('no such customer')));
        $this->whatsAppSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenPhoneAttributeMissing(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone(null));
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsOptedOutWhenConsentWithdrawn(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->consentManager->expects(self::once())->method('hasConsent')
            ->with(42, ConsentChannel::WhatsApp)->willReturn(false);
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('info');
        $this->messageLogWriter->expects(self::once())->method('recordOptedOut')
            ->with('whatsapp', 42, '+15551234567');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenQuietHoursGateDefers(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->quietHoursGate = $this->createMock(QuietHoursGate::class);
        $this->quietHoursGate->expects(self::once())->method('allows')->willReturn(false);
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::never())->method('allows');
        $this->whatsAppSender->expects(self::never())->method('send');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAndRecordsSuppressedWhenFrequencyCapReached(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->frequencyCapGate = $this->createMock(FrequencyCapGate::class);
        $this->frequencyCapGate->expects(self::once())->method('allows')
            ->with(42, 'whatsapp', '+15551234567', 'send_whatsapp')->willReturn(false);
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::never())->method('info');
        $this->messageLogWriter->expects(self::never())->method('recordSuppressed');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRecordsFailedWhenPhoneIsNotValidE164(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('0123456789'));
        $this->whatsAppSender->expects(self::never())->method('send');
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')
            ->with('whatsapp', 42, '0123456789');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSenderThrowsAndDoesNotRethrow(): void
    {
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->whatsAppSender->expects(self::exactly(3))->method('send')
            ->willThrowException(new \RuntimeException('meta api down'));
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')
            ->with('whatsapp', 42, '+15551234567');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);

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
        $this->stubApprovedTemplate();
        $this->customerRepository->method('getById')->willReturn($this->customerWithPhone('+15551234567'));
        $this->whatsAppSender->expects(self::exactly(2))->method('send')->willReturnCallback(
            function () {
                static $calls = 0;
                $calls++;
                if ($calls < 2) {
                    throw new \RuntimeException('transient graph api timeout');
                }
                return 'wamid.123';
            }
        );
        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('whatsapp', 42, '+15551234567', 'wamid.123');
        $this->messageLogWriter->expects(self::never())->method('recordFailed');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template_id' => '3']);
    }
}
