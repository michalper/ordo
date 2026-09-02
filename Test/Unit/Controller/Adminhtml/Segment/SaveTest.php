<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Segment\Save;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private SegmentSaveProcessor $saveProcessor;

    protected function setUp(): void
    {
        $this->saveProcessor = $this->createMock(SegmentSaveProcessor::class);
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
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->saveProcessor->expects(self::never())->method('process');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewSegmentAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'VIP customers',
            'enabled' => '1',
            'conditions' => ['conditions' => [['type' => 'lifetime_spend', 'params_json' => '{"min":"500"}']]],
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->saveProcessor->expects(self::once())->method('process')->with($postData)->willReturn($segment);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $postData = ['name' => 'VIP customers'];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $segment = $this->createMock(Segment::class);
        $segment->method('getEntityId')->willReturn(7);
        $this->saveProcessor->method('process')->with($postData)->willReturn($segment);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'VIP customers']);

        $this->saveProcessor->method('process')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
