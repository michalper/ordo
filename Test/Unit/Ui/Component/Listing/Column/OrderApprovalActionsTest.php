<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Api\Data\OrderApprovalDecisionLinksInterface;
use Ordo\Automation\Api\OrderApprovalManagementInterface;
use Ordo\Automation\Ui\Component\Listing\Column\OrderApprovalActions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderApprovalActionsTest extends TestCase
{
    private function makeContext(): ContextInterface
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        return $context;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceReturnsUnchangedWithoutItems(): void
    {
        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $this->createStub(OrderApprovalManagementInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        self::assertSame([], $column->prepareDataSource([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceAddsApproveAndRejectLinksForAPendingRow(): void
    {
        $links = $this->createStub(OrderApprovalDecisionLinksInterface::class);
        $links->method('getApproveUrl')->willReturn('https://example.com/ordo/approval/approve/token/abc/');
        $links->method('getRejectUrl')->willReturn('https://example.com/ordo/approval/reject/token/abc/');

        $management = $this->createMock(OrderApprovalManagementInterface::class);
        $management->expects(self::once())->method('getDecisionLinksById')->with(5)->willReturn($links);

        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $management,
            $this->createStub(LoggerInterface::class)
        );
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [['entity_id' => 5, 'status' => 'pending']]]];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertSame('https://example.com/ordo/approval/approve/token/abc/', $actions['approve']['href']);
        self::assertSame('https://example.com/ordo/approval/reject/token/abc/', $actions['reject']['href']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceSkipsANonPendingRowWithoutCallingTheManagementService(): void
    {
        $management = $this->createMock(OrderApprovalManagementInterface::class);
        $management->expects(self::never())->method('getDecisionLinksById');

        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $management,
            $this->createStub(LoggerInterface::class)
        );
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [['entity_id' => 5, 'status' => 'approved']]]];

        $result = $column->prepareDataSource($dataSource);

        self::assertArrayNotHasKey('actions', $result['data']['items'][0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceSkipsARowThatBecameNoLongerPendingBetweenLoadAndRender(): void
    {
        $management = $this->createStub(OrderApprovalManagementInterface::class);
        $management->method('getDecisionLinksById')->willThrowException(
            new NoSuchEntityException(__('No such entity'))
        );

        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $management,
            $this->createStub(LoggerInterface::class)
        );
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [['entity_id' => 5, 'status' => 'pending']]]];

        $result = $column->prepareDataSource($dataSource);

        self::assertArrayNotHasKey('actions', $result['data']['items'][0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceLogsAndSkipsOnAnUnexpectedFailure(): void
    {
        $management = $this->createStub(OrderApprovalManagementInterface::class);
        $management->method('getDecisionLinksById')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('boom'));

        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $management,
            $logger
        );
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [['entity_id' => 5, 'status' => 'pending']]]];

        $result = $column->prepareDataSource($dataSource);

        self::assertArrayNotHasKey('actions', $result['data']['items'][0]);
    }
}
