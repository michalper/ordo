<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\SegmentActions;
use PHPUnit\Framework\TestCase;

class SegmentActionsTest extends TestCase
{
    public function testPrepareDataSourceReturnsUnchangedWithoutItems(): void
    {
        $column = $this->makeColumn();

        self::assertSame([], $column->prepareDataSource([]));
    }

    public function testPrepareDataSourceAddsEditAndDeleteLinks(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([
            ['ordo/segment/edit', ['entity_id' => 5], 'https://example.com/admin/ordo/segment/edit/entity_id/5/'],
            ['ordo/segment/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/segment/delete/entity_id/5/'],
        ]);

        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(\Magento\Framework\View\Element\UiComponent\Processor::class));

        $column = new SegmentActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'name' => 'VIP customers'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('entity_id/5', $actions['edit']['href']);
        self::assertStringContainsString('entity_id/5', $actions['delete']['href']);
        self::assertStringContainsString('segment', (string) $actions['delete']['confirm']['title']);
        self::assertStringContainsString('VIP customers', (string) $actions['delete']['confirm']['title']);
    }

    private function makeColumn(): SegmentActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(\Magento\Framework\View\Element\UiComponent\Processor::class));

        return new SegmentActions(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }
}
