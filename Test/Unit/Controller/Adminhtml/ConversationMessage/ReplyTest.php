<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ConversationMessage;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ConversationMessage\Reply;
use Ordo\Automation\Model\Conversation\OutboundReplySender;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ReplyTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsTheReplyAndRedirectsWithASuccessMessage(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['entity_id', 5],
            ['body', 'Your order shipped!'],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $outboundReplySender = $this->createMock(OutboundReplySender::class);
        $outboundReplySender->expects(self::once())->method('reply')
            ->with(5, 'Your order shipped!')
            ->willReturn($this->createStub(ConversationMessage::class));

        $this->messageManager->expects(self::once())->method('addSuccessMessage')->with(__('Reply sent.'));
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $controller = new Reply($context, $outboundReplySender);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithAnErrorMessageWhenSendingFails(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['entity_id', 5],
            ['body', 'Hi there!'],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $outboundReplySender = $this->createMock(OutboundReplySender::class);
        $outboundReplySender->method('reply')->willThrowException(new \RuntimeException('customer opted out'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('Could not send the reply: %1', 'customer opted out'));
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = new Reply($context, $outboundReplySender);
        self::assertSame($redirect, $controller->execute());
    }
}
