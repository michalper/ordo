<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ViewInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/**
 * Mocks just enough of Backend\App\Action\Context for Action::__construct() to wire up
 * $this->_request / $this->messageManager / $this->resultRedirectFactory without a real
 * dispatch cycle.
 */
abstract class AbstractAdminActionTestCase extends TestCase
{
    protected Http $request;
    protected MessageManagerInterface $messageManager;
    protected RedirectFactory $resultRedirectFactory;

    protected function makeContext(): Context
    {
        $this->request = $this->createMock(Http::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->resultRedirectFactory = $this->createStub(RedirectFactory::class);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResponse')->willReturn($this->createStub(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getObjectManager')->willReturn($this->createStub(ObjectManagerInterface::class));
        $context->method('getEventManager')->willReturn($this->createStub(EventManagerInterface::class));
        $context->method('getUrl')->willReturn($this->createStub(UrlInterface::class));
        $context->method('getRedirect')->willReturn($this->createStub(RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createStub(ActionFlag::class));
        $context->method('getView')->willReturn($this->createStub(ViewInterface::class));
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($this->resultRedirectFactory);
        $context->method('getResultFactory')->willReturn($this->createStub(\Magento\Framework\Controller\ResultFactory::class));

        return $context;
    }
}
