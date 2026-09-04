<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Offer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Ordo\Automation\Controller\Offer\AbstractOfferAction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AbstractOfferActionTest extends TestCase
{
    private CustomerSession&\PHPUnit\Framework\MockObject\MockObject $customerSession;
    private CustomerUrl $customerUrl;
    private ActionFlag $actionFlag;
    private HttpRequest $request;
    private ResponseInterface $response;

    protected function setUp(): void
    {
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->customerUrl = $this->createStub(CustomerUrl::class);
        $this->customerUrl->method('getLoginUrl')->willReturn('https://example.com/customer/account/login/');

        $this->request = $this->createStub(HttpRequest::class);
        $this->request->method('isDispatched')->willReturn(true);
        $this->request->method('getActionName')->willReturn('index');

        // ActionFlag::get()/set() are exercised for real (not mocked) — that's the actual
        // mechanism Action::dispatch() checks to decide whether to call execute() at all, so
        // stubbing it away would test nothing but that this class calls some method named "set".
        $this->actionFlag = new ActionFlag($this->request);

        $this->response = $this->createStub(ResponseInterface::class);
    }

    private function makeController(): AbstractOfferAction
    {
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResponse')->willReturn($this->response);
        $context->method('getActionFlag')->willReturn($this->actionFlag);

        return new class ($context, $this->customerSession, $this->customerUrl) extends AbstractOfferAction {
            public function execute()
            {
                return 'executed';
            }
        };
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchRunsExecuteWhenCustomerIsAuthenticated(): void
    {
        $this->customerSession->method('authenticate')->willReturn(true);

        $controller = $this->makeController();

        $result = $controller->dispatch($this->request);

        self::assertSame('executed', $result);
        self::assertFalse($this->actionFlag->get('', \Magento\Framework\App\Action\AbstractAction::FLAG_NO_DISPATCH));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSkipsExecuteAndRedirectsToLoginWhenNotAuthenticated(): void
    {
        $this->customerSession->method('authenticate')->willReturn(false);

        $controller = $this->makeController();

        $result = $controller->dispatch($this->request);

        self::assertSame($this->response, $result);
        self::assertTrue($this->actionFlag->get('', \Magento\Framework\App\Action\AbstractAction::FLAG_NO_DISPATCH));
    }
}
