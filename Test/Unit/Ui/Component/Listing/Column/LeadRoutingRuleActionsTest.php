<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\LeadRoutingRuleActions;
use PHPUnit\Framework\TestCase;

class LeadRoutingRuleActionsTest extends TestCase
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
            ['ordo/leadroutingrule/edit', ['entity_id' => 5], 'https://example.com/admin/ordo/leadroutingrule/edit/entity_id/5/'],
            ['ordo/leadroutingrule/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/leadroutingrule/delete/entity_id/5/'],
        ]);

        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        $column = new LeadRoutingRuleActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'name' => 'EU B2B leads'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('entity_id/5', $actions['edit']['href']);
        self::assertStringContainsString('entity_id/5', $actions['delete']['href']);
        self::assertTrue($actions['delete']['post'], 'delete action must submit via POST, not a plain GET navigation');
        self::assertStringContainsString('lead routing rule', (string) $actions['delete']['confirm']['title']);
        self::assertStringContainsString('EU B2B leads', (string) $actions['delete']['confirm']['title']);
    }

    private function makeColumn(): LeadRoutingRuleActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        return new LeadRoutingRuleActions(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }
}
