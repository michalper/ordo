<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Segment\BulkAction;
use Ordo\Automation\Model\Queue\SegmentBulkActionPublisher;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class BulkActionTest extends AbstractAdminActionTestCase
{
    private SegmentFactory $segmentFactory;
    private SegmentResource $segmentResource;
    private SegmentMemberResolver $segmentMemberResolver;
    private SegmentBulkActionPublisher $segmentBulkActionPublisher;
    private Segment $segment;

    protected function setUp(): void
    {
        $this->segmentResource = $this->createMock(SegmentResource::class);
        $this->segmentMemberResolver = $this->createMock(SegmentMemberResolver::class);
        $this->segmentBulkActionPublisher = $this->createMock(SegmentBulkActionPublisher::class);

        $this->segment = $this->createMock(Segment::class);
        $this->segmentFactory = $this->createMock(SegmentFactory::class);
        $this->segmentFactory->method('create')->willReturn($this->segment);
    }

    private function makeController(): BulkAction
    {
        return new BulkAction(
            $this->makeContext(),
            $this->segmentFactory,
            $this->segmentResource,
            $this->segmentMemberResolver,
            $this->segmentBulkActionPublisher
        );
    }

    private function makeRedirect(): Redirect
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);
        return $redirect;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenSegmentIdInvalid(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 0],
            ['action_type', null, 'add_tag'],
        ]);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenSegmentDoesNotExist(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'add_tag'],
        ]);
        $this->segment->method('getEntityId')->willReturn(null);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        self::assertNotNull($controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenActionTypeInvalid(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'delete_everything'],
        ]);
        $this->segment->method('getEntityId')->willReturn(5);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenTagMissingForAddTag(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'add_tag'],
            ['tag', null, ''],
        ]);
        $this->segment->method('getEntityId')->willReturn(5);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenPointsNotNumericForAddPoints(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'add_points'],
            ['points', null, 'not-a-number'],
        ]);
        $this->segment->method('getEntityId')->willReturn(5);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWarnsAndDoesNotPublishWhenNoMatches(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'add_tag'],
            ['tag', null, 'vip'],
        ]);
        $this->segment->method('getEntityId')->willReturn(5);
        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturnMap([[5, []]]);

        $this->messageManager->expects(self::once())->method('addWarningMessage');
        $this->segmentBulkActionPublisher->expects(self::never())->method('publish');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecutePublishesAndFlashesSuccessOnMatch(): void
    {
        $controller = $this->makeController();
        $this->makeRedirect();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id', null, 5],
            ['action_type', null, 'add_points'],
            ['points', null, '10'],
        ]);
        $this->segment->method('getEntityId')->willReturn(5);
        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturnMap([[5, [1, 2, 3]]]);

        $this->segmentBulkActionPublisher->expects(self::once())
            ->method('publish')
            ->with(5, 'add_points', ['points' => 10], [1, 2, 3]);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $controller->execute();
    }
}
