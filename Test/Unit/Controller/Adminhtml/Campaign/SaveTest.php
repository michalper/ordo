<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Campaign\Save;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignSaveProcessor;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private CampaignSaveProcessor $saveProcessor;

    protected function setUp(): void
    {
        $this->saveProcessor = $this->createMock(CampaignSaveProcessor::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->saveProcessor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->saveProcessor->expects(self::never())->method('process');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewCampaignAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'Welcome',
            'enabled' => '1',
            'triggers' => ['triggers' => [['trigger_event' => 'customer_registered']]],
            'conditions' => ['conditions' => [['type' => 'has_tag', 'tag' => 'vip', 'params_json' => '']]],
            'actions' => ['actions' => [['type' => 'tag_customer', 'tag' => 'reordered', 'params_json' => '', 'delay_minutes' => '60']]],
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->saveProcessor->expects(self::once())->method('process')->with($postData)->willReturn($campaign);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $postData = ['name' => 'Welcome'];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', '1']]);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->saveProcessor->method('process')->willReturnMap([[$postData, $campaign]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'Welcome']);

        $this->saveProcessor->method('process')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
