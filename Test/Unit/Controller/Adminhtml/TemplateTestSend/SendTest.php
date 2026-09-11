<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\TemplateTestSend;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Ordo\Automation\Controller\Adminhtml\TemplateTestSend\Send;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendTest extends AbstractAdminActionTestCase
{
    private TransportBuilder $transportBuilder;
    private SmsSenderInterface $smsSender;
    private WhatsAppSender $whatsAppSender;
    private WhatsAppTemplateFactory $whatsAppTemplateFactory;
    private WhatsAppTemplateResource $whatsAppTemplateResource;

    protected function setUp(): void
    {
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        $this->smsSender = $this->createMock(SmsSenderInterface::class);
        $this->whatsAppSender = $this->createMock(WhatsAppSender::class);
        $this->whatsAppTemplateFactory = $this->createMock(WhatsAppTemplateFactory::class);
        $this->whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
    }

    private function makeController(\Magento\Backend\App\Action\Context $context): Send
    {
        $result = $this->createStub(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->lastResultData = $data;
            return $result;
        });
        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        return new Send(
            $context,
            $resultJsonFactory,
            $this->transportBuilder,
            $this->smsSender,
            $this->whatsAppSender,
            $this->whatsAppTemplateFactory,
            $this->whatsAppTemplateResource,
            new SendRetrier(1)
        );
    }

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsATestEmail(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'email'],
            ['to', null, 'test@example.com'],
            ['message', '', 'Hello!'],
        ]);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->with('test@example.com')->willReturnSelf();
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertStringContainsString('test@example.com', $this->lastResultData['message']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRejectsAnInvalidEmailAddressWithoutSending(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'email'],
            ['to', null, 'not-an-email'],
            ['message', '', ''],
        ]);

        $this->transportBuilder->expects(self::never())->method('getTransport');

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsATestSms(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'sms'],
            ['to', null, '+15551234567'],
            ['message', '', 'Hello!'],
        ]);

        $this->smsSender->expects(self::once())->method('send')->with('+15551234567', 'Hello!')
            ->willReturn('SM123');

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertStringContainsString('+15551234567', $this->lastResultData['message']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRejectsAnInvalidPhoneNumberForSmsWithoutSending(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'sms'],
            ['to', null, 'not-a-phone'],
            ['message', '', 'Hello!'],
        ]);

        $this->smsSender->expects(self::never())->method('send');

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsATestWhatsAppMessageForAnApprovedTemplate(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'whatsapp'],
            ['to', null, '+15551234567'],
            ['template_id', null, 3],
            ['params', '', 'value1,value2'],
        ]);

        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(3);
        $template->method('isApproved')->willReturn(true);
        $template->method('getMetaTemplateName')->willReturn('order_shipped');
        $template->method('getLanguage')->willReturn('en_US');

        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppSender->expects(self::once())->method('send')
            ->with('+15551234567', 'order_shipped', 'en_US', ['value1', 'value2'])
            ->willReturn('wamid.123');

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertTrue($this->lastResultData['success']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRejectsAnUnapprovedWhatsAppTemplateWithoutSending(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'whatsapp'],
            ['to', null, '+15551234567'],
            ['template_id', null, 3],
            ['params', '', ''],
        ]);

        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(3);
        $template->method('isApproved')->willReturn(false);

        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppSender->expects(self::never())->method('send');

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsAnErrorForAnUnknownChannel(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'carrier_pigeon'],
        ]);

        $controller = $this->makeController($context);
        $controller->execute();

        self::assertFalse($this->lastResultData['success']);
    }
}
