<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Ui\Component\Listing\Column\ConversationMessageActions;
use PHPUnit\Framework\TestCase;

class ConversationMessageActionsTest extends TestCase
{
    private function makeColumn(UrlInterface $urlBuilder): ConversationMessageActions
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        $column = new ConversationMessageActions($context, $this->createStub(UiComponentFactory::class), $urlBuilder);
        $column->setData('name', 'actions');

        return $column;
    }

    public function testPrepareDataSourceReturnsUnchangedWithoutItems(): void
    {
        $column = $this->makeColumn($this->createStub(UrlInterface::class));

        self::assertSame([], $column->prepareDataSource([]));
    }

    public function testPrepareDataSourceAddsAReplyLinkForAnInboundRow(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')
            ->with('ordo/conversationmessage/index', ['reply_to' => 5])
            ->willReturn('https://example.com/admin/ordo/conversationmessage/index/reply_to/5/');

        $column = $this->makeColumn($urlBuilder);
        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'direction' => ConversationMessage::DIRECTION_INBOUND],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        $actions = $result['data']['items'][0]['actions'];
        self::assertStringContainsString('reply_to/5', $actions['reply']['href']);
    }

    public function testPrepareDataSourceOmitsReplyLinkForAnOutboundRow(): void
    {
        $column = $this->makeColumn($this->createStub(UrlInterface::class));
        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 6, 'direction' => ConversationMessage::DIRECTION_OUTBOUND],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        self::assertArrayNotHasKey('actions', $result['data']['items'][0]);
    }

    public function testPrepareDataSourceTreatsAMissingDirectionAsInbound(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/conversationmessage/index/reply_to/9/');

        $column = $this->makeColumn($urlBuilder);
        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 9],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        self::assertArrayHasKey('actions', $result['data']['items'][0]);
    }
}
