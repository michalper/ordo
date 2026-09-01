<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Controller\Adminhtml\Campaign\Delete;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

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

        $campaignFactory = $this->createStub(CampaignFactory::class);
        $campaignResource = $this->createStub(CampaignResource::class);
        $cache = $this->createStub(CacheInterface::class);

        $controller = new Delete($context, $campaignFactory, $campaignResource, $cache);
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

        $campaign = $this->createStub(Campaign::class);
        $campaignFactory = $this->createStub(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->expects(self::once())->method('delete')->with($campaign);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        $controller = new Delete($context, $campaignFactory, $campaignResource, $cache);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $campaign = $this->createStub(Campaign::class);
        $campaignFactory = $this->createStub(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $controller = new Delete($context, $campaignFactory, $campaignResource, $cache);
        self::assertSame($redirect, $controller->execute());
    }
}
