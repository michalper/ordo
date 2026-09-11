<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Controller\Adminhtml\Segment\Export;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ExportTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new Export(
            $context,
            $this->createStub(RawFactory::class),
            $this->createStub(SegmentFactory::class),
            $this->createStub(SegmentResource::class),
            $this->createStub(ConditionCollectionFactory::class)
        );

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenSegmentNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(null);
        $segmentFactory = $this->createStub(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $controller = new Export(
            $context,
            $this->createStub(RawFactory::class),
            $segmentFactory,
            $this->createStub(SegmentResource::class),
            $this->createStub(ConditionCollectionFactory::class)
        );

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsJsonDownloadOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(5);
        $segment->method('getName')->willReturn('VIP customers');
        $segment->method('isEnabled')->willReturn(true);
        $segment->method('getConditionLogic')->willReturn('all');
        $segmentFactory = $this->createStub(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $condition = $this->createStub(SegmentCondition::class);
        $condition->method('getType')->willReturn('monetary_total_at_least');
        $condition->method('getParams')->willReturn(['amount' => 500]);
        $condition->method('getSortOrder')->willReturn(0);
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addSegmentFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $conditionCollectionFactory = $this->createStub(ConditionCollectionFactory::class);
        $conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $raw = $this->createMock(Raw::class);
        $raw->expects(self::exactly(2))->method('setHeader')->willReturnSelf();
        $raw->expects(self::once())->method('setContents')
            ->with(self::callback(function ($json) {
                $decoded = json_decode($json, true);
                return $decoded['export_type'] === 'ordo_segment'
                    && $decoded['name'] === 'VIP customers'
                    && $decoded['conditions'][0]['type'] === 'monetary_total_at_least'
                    && !isset($decoded['entity_id']);
            }))
            ->willReturnSelf();
        $resultRawFactory = $this->createStub(RawFactory::class);
        $resultRawFactory->method('create')->willReturn($raw);

        $controller = new Export(
            $context,
            $resultRawFactory,
            $segmentFactory,
            $this->createStub(SegmentResource::class),
            $conditionCollectionFactory
        );

        self::assertSame($raw, $controller->execute());
    }
}
