<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\ReorderCycleActions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ReorderCycleActionsTest extends TestCase
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
        $column = new ReorderCycleActions(
            $this->makeContext(),
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );

        self::assertSame([], $column->prepareDataSource([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceAddsSendReminderLinkForEachRow(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())->method('getUrl')
            ->with('ordo/reordercycle/sendreminder', ['entity_id' => 5])
            ->willReturn('https://example.com/admin/ordo/reordercycle/sendreminder/entity_id/5/');

        $column = new ReorderCycleActions($this->makeContext(), $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = ['data' => ['items' => [['entity_id' => 5]]]];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertSame(
            'https://example.com/admin/ordo/reordercycle/sendreminder/entity_id/5/',
            $actions['send_reminder']['href']
        );
        self::assertTrue($actions['send_reminder']['post']);
    }
}
