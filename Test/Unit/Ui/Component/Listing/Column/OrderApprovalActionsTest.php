<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

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
        $management->expects(self::once())->method('getDecisionLinksByIds')->with([5])->willReturn([5 => $links]);

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

    /**
     * Regression: prepareDataSource() used to call getDecisionLinksById() once per pending row -
     * a real N+1 a code audit found. It must now collect every pending row's id and call the
     * batched getDecisionLinksByIds() exactly once for the whole page, regardless of how many
     * pending rows are on it.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceBatchesAllPendingRowsIntoOneCall(): void
    {
        $links5 = $this->createStub(OrderApprovalDecisionLinksInterface::class);
        $links5->method('getApproveUrl')->willReturn('https://example.com/5/approve');
        $links5->method('getRejectUrl')->willReturn('https://example.com/5/reject');

        $links7 = $this->createStub(OrderApprovalDecisionLinksInterface::class);
        $links7->method('getApproveUrl')->willReturn('https://example.com/7/approve');
        $links7->method('getRejectUrl')->willReturn('https://example.com/7/reject');

        $management = $this->createMock(OrderApprovalManagementInterface::class);
        $management->expects(self::once())->method('getDecisionLinksByIds')
            ->with([5, 7])
            ->willReturn([5 => $links5, 7 => $links7]);

        $column = new OrderApprovalActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $management,
            $this->createStub(LoggerInterface::class)
        );
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [
            ['entity_id' => 5, 'status' => 'pending'],
            ['entity_id' => 6, 'status' => 'approved'],
            ['entity_id' => 7, 'status' => 'pending'],
        ]]];

        $result = $column->prepareDataSource($dataSource);

        self::assertSame('https://example.com/5/approve', $result['data']['items'][0]['actions']['approve']['href']);
        self::assertArrayNotHasKey('actions', $result['data']['items'][1]);
        self::assertSame('https://example.com/7/approve', $result['data']['items'][2]['actions']['approve']['href']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceSkipsANonPendingRowWithoutCallingTheManagementService(): void
    {
        $management = $this->createMock(OrderApprovalManagementInterface::class);
        $management->expects(self::never())->method('getDecisionLinksByIds');

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
        // The batched method's own contract: a row that's since been decided (or never existed)
        // is simply absent from the returned map, not an exception - same "no actions to show"
        // outcome as before, just expressed differently now that this is one call for the page.
        $management = $this->createStub(OrderApprovalManagementInterface::class);
        $management->method('getDecisionLinksByIds')->willReturn([]);

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
        $management->method('getDecisionLinksByIds')->willThrowException(new \RuntimeException('boom'));

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
