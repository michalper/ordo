<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Approval;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Controller\Approval\Reject;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RejectTest extends AbstractFrontendActionTestCase
{
    private OrderApprovalManagement $orderApprovalManagement;
    private OrderRepositoryInterface $orderRepository;
    private Page $resultPage;

    protected function setUp(): void
    {
        $this->orderApprovalManagement = $this->createMock(OrderApprovalManagement::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    private function makeController(): Reject
    {
        return new Reject(
            $this->makeContext(),
            $this->orderApprovalManagement,
            $this->orderRepository
        );
    }

    /**
     * Same Page-result wiring as Consent\IndexTest's own makeController() - a GET here renders
     * the confirm page instead of using the shared base class's redirect-only context.
     */
    private function makeControllerForGetRequest(): Reject
    {
        $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('set')->with('Order Approval');
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $this->resultPage = $this->createStub(Page::class);
        $this->resultPage->method('getConfig')->willReturn($pageConfig);

        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->resultFactory->expects(self::once())->method('create')
            ->with(ResultFactory::TYPE_PAGE)
            ->willReturn($this->resultPage);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultFactory')->willReturn($this->resultFactory);

        return new Reject($context, $this->orderApprovalManagement, $this->orderRepository);
    }

    /**
     * Regression for a real security gap: a plain GET used to perform the rejection directly, so
     * an automated link-prefetch (corporate email-security gateways, some mail clients) would
     * silently burn the single-use token before a human ever saw the email. GET must now only
     * render the confirmation page - never call rejectByToken() at all.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRendersTheConfirmPageWithoutActingOnAGetRequest(): void
    {
        $controller = $this->makeControllerForGetRequest();
        $this->request->method('isPost')->willReturn(false);

        $this->orderApprovalManagement->expects(self::never())->method('rejectByToken');
        $this->orderRepository->expects(self::never())->method('get');

        self::assertSame($this->resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenTokenInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['token', '']]);
        $this->orderApprovalManagement->method('rejectByToken')
            ->willThrowException(new NoSuchEntityException(__('invalid')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->orderRepository->expects(self::never())->method('get');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenOrderCannotBeFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['token', 'tok']]);
        $this->orderApprovalManagement->method('rejectByToken')
            ->willThrowException(new LocalizedException(__('The order for this approval could not be found.')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRejectsOrderAndRedirectsWithSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['token', 'tok']]);

        $approval = $this->createStub(OrderApproval::class);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalManagement->method('rejectByToken')->willReturnMap([['tok', $approval]]);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000007');
        $this->orderRepository->method('get')->willReturnMap([[7, $order]]);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    /**
     * Rate limiting moved into OrderApprovalManagement (shared with the REST API - see its own
     * docblock), so this controller no longer pre-checks it itself; it only needs to turn
     * whatever LocalizedException-derived exception the service throws (including the
     * WebapiException a rate-limited attempt now raises) into the same friendly redirect message.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenRateLimited(): void
    {
        $controller = $this->makeController();
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['token', 'tok']]);
        $this->orderApprovalManagement->method('rejectByToken')->willThrowException(
            new WebapiException(
                __('Too many attempts. Please wait a while and try again.'),
                0,
                WebapiException::HTTP_TOO_MANY_REQUESTS
            )
        );

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
