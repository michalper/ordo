<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ProductFeed;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ProductFeed\RefreshNow;
use Ordo\Automation\Model\ProductFeed\ProductFeedRefresher;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RefreshNowTest extends AbstractAdminActionTestCase
{
    private function makeRedirect(): Redirect
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        return $redirect;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRefreshesWritesSuccessMessageAndRedirects(): void
    {
        $context = $this->makeContext();
        $redirect = $this->makeRedirect();

        $refresher = $this->createMock(ProductFeedRefresher::class);
        $refresher->expects(self::once())->method('refreshAll')->willReturn([
            'generated' => 1,
            'skipped' => 0,
            'failed' => 0,
            'totalProducts' => 7,
            'errors' => [],
        ]);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $controller = new RefreshNow($context, $refresher);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWritesErrorMessagesAndRedirectsWhenRefresherReportsFailures(): void
    {
        $context = $this->makeContext();
        $redirect = $this->makeRedirect();

        $refresher = $this->createMock(ProductFeedRefresher::class);
        $refresher->expects(self::once())->method('refreshAll')->willReturn([
            'generated' => 0,
            'skipped' => 0,
            'failed' => 1,
            'totalProducts' => 0,
            'errors' => ['Main Website Store (google_merchant): catalog error'],
        ]);

        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new RefreshNow($context, $refresher);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithoutAnyMessageWhenEverythingWasSkipped(): void
    {
        $context = $this->makeContext();
        $redirect = $this->makeRedirect();

        $refresher = $this->createMock(ProductFeedRefresher::class);
        $refresher->expects(self::once())->method('refreshAll')->willReturn([
            'generated' => 0,
            'skipped' => 1,
            'failed' => 0,
            'totalProducts' => 0,
            'errors' => [],
        ]);

        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $controller = new RefreshNow($context, $refresher);
        self::assertSame($redirect, $controller->execute());
    }
}
