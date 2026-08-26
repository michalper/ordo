<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ViewInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

abstract class AbstractFrontendActionTestCase extends TestCase
{
    protected Http $request;
    protected MessageManagerInterface $messageManager;
    protected ResultFactory $resultFactory;
    protected Redirect $resultRedirect;

    protected function makeContext(): Context
    {
        $this->request = $this->createMock(Http::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);

        $this->resultRedirect = $this->createMock(Redirect::class);
        $this->resultRedirect->method('setPath')->willReturnSelf();

        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->resultFactory->method('create')->willReturn($this->resultRedirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getObjectManager')->willReturn($this->createMock(ObjectManagerInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(EventManagerInterface::class));
        $context->method('getUrl')->willReturn($this->createMock(UrlInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(ActionFlag::class));
        $context->method('getView')->willReturn($this->createMock(ViewInterface::class));
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class));
        $context->method('getResultFactory')->willReturn($this->resultFactory);

        return $context;
    }
}
