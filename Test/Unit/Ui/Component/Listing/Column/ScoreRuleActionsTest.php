<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\ScoreRuleActions;
use PHPUnit\Framework\TestCase;

class ScoreRuleActionsTest extends TestCase
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
            ['ordo/scorerule/edit', ['entity_id' => 5], 'https://example.com/admin/ordo/scorerule/edit/entity_id/5/'],
            ['ordo/scorerule/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/scorerule/delete/entity_id/5/'],
        ]);

        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        $column = new ScoreRuleActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = [
            'data' => [
                // Grid\Collection::_initSelect() aliases attribute_code as "name" — there's no
                // real "name" column on ordo_score_rule — so this is what a real row looks like.
                'items' => [
                    ['entity_id' => 5, 'name' => 'group_id'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('entity_id/5', $actions['edit']['href']);
        self::assertStringContainsString('entity_id/5', $actions['delete']['href']);
        self::assertStringContainsString('score rule', (string) $actions['delete']['confirm']['title']);
        self::assertStringContainsString('group_id', (string) $actions['delete']['confirm']['title']);
    }

    private function makeColumn(): ScoreRuleActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        return new ScoreRuleActions(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }
}
