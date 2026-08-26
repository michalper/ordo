<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Campaign\Delete;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class DeleteTest extends AbstractAdminActionTestCase
{
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignResource = $this->createMock(CampaignResource::class);

        $controller = new Delete($context, $campaignFactory, $campaignResource);
        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteDeletesAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $campaign = $this->createMock(Campaign::class);
        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->expects(self::once())->method('delete')->with($campaign);

        $controller = new Delete($context, $campaignFactory, $campaignResource);
        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $campaign = $this->createMock(Campaign::class);
        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $campaignFactory, $campaignResource);
        self::assertSame($redirect, $controller->execute());
    }
}
