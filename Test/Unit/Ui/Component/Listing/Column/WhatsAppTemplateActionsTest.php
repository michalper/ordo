<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\WhatsAppTemplateActions;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateActionsTest extends TestCase
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
            ['ordo/whatsapptemplate/edit', ['entity_id' => 5], 'https://example.com/admin/ordo/whatsapptemplate/edit/entity_id/5/'],
            ['ordo/whatsapptemplate/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/whatsapptemplate/delete/entity_id/5/'],
        ]);

        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        $column = new WhatsAppTemplateActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'name' => 'Order Shipped'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('entity_id/5', $actions['edit']['href']);
        self::assertStringContainsString('entity_id/5', $actions['delete']['href']);
        self::assertTrue($actions['delete']['post'], 'delete action must submit via POST, not a plain GET navigation');
        self::assertStringContainsString('WhatsApp template', (string) $actions['delete']['confirm']['title']);
        self::assertStringContainsString('Order Shipped', (string) $actions['delete']['confirm']['title']);
    }

    private function makeColumn(): WhatsAppTemplateActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        return new WhatsAppTemplateActions(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }
}
