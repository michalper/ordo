<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Ui\Component\Listing\Column\EmailTemplateDraftActions;
use PHPUnit\Framework\TestCase;

class EmailTemplateDraftActionsTest extends TestCase
{
    public function testPrepareDataSourceReturnsUnchangedWithoutItems(): void
    {
        $column = $this->makeColumn($this->createStub(UrlInterface::class));

        self::assertSame([], $column->prepareDataSource([]));
    }

    public function testPrepareDataSourceAddsEditPublishAndDeleteLinks(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([
            ['ordo/emailtemplatedraft/edit', ['entity_id' => 5], 'https://example.com/admin/ordo/emailtemplatedraft/edit/entity_id/5/'],
            ['ordo/emailtemplatedraft/publish', ['entity_id' => 5], 'https://example.com/admin/ordo/emailtemplatedraft/publish/entity_id/5/'],
            ['ordo/emailtemplatedraft/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/emailtemplatedraft/delete/entity_id/5/'],
        ]);

        $column = $this->makeColumn($urlBuilder);
        $column->setData('name', 'actions');

        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'template_code' => 'welcome_email'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('entity_id/5', $actions['edit']['href']);
        self::assertStringContainsString('entity_id/5', $actions['publish']['href']);
        self::assertStringContainsString('entity_id/5', $actions['delete']['href']);
        self::assertTrue($actions['publish']['post'], 'publish must submit via POST, not a plain GET navigation');
        self::assertTrue($actions['delete']['post'], 'delete must submit via POST, not a plain GET navigation');
        self::assertStringContainsString('email template draft', (string) $actions['delete']['confirm']['title']);
        self::assertStringContainsString('welcome_email', (string) $actions['delete']['confirm']['title']);
    }

    private function makeColumn(UrlInterface $urlBuilder): EmailTemplateDraftActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        return new EmailTemplateDraftActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
    }
}
