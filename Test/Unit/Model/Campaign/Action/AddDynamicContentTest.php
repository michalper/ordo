<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Campaign\Action\AddDynamicContent;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\Producer\ProducerInterface;
use Ordo\Automation\Model\ContentBlock\ProducerPool;
use Ordo\Automation\Model\ContentBlockRepository;
use PHPUnit\Framework\TestCase;

class AddDynamicContentTest extends TestCase
{
    private ContentBlockRepository&\PHPUnit\Framework\MockObject\MockObject $contentBlockRepository;
    private ProducerPool&\PHPUnit\Framework\MockObject\MockObject $producerPool;
    private AddDynamicContent $action;

    protected function setUp(): void
    {
        $this->contentBlockRepository = $this->createMock(ContentBlockRepository::class);
        $this->producerPool = $this->createMock(ProducerPool::class);
        $this->action = new AddDynamicContent($this->contentBlockRepository, $this->producerPool);
    }

    private function makeBlock(string $type, bool $enabled): ContentBlock
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setType($type);
        $block->setEnabled($enabled);

        return $block;
    }

    public function testBlockIdMissingSetsEmptyStringOnDefaultKey(): void
    {
        $this->contentBlockRepository->expects(self::never())->method('getById');
        $this->producerPool->expects(self::never())->method('get');

        $context = [];
        $this->action->execute($context, []);

        self::assertSame('', $context['dynamic_content_html']);
    }

    public function testBlockIdZeroOrNegativeSetsEmptyString(): void
    {
        $this->contentBlockRepository->expects(self::never())->method('getById');
        $this->producerPool->expects(self::never())->method('get');

        $context = [];
        $this->action->execute($context, ['content_block_id' => 0]);
        self::assertSame('', $context['dynamic_content_html']);

        $context = [];
        $this->action->execute($context, ['content_block_id' => -3]);
        self::assertSame('', $context['dynamic_content_html']);
    }

    public function testBlockNotFoundSetsEmptyString(): void
    {
        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn(null);
        $this->producerPool->expects(self::never())->method('get');

        $context = [];
        $this->action->execute($context, ['content_block_id' => 5]);

        self::assertSame('', $context['dynamic_content_html']);
    }

    public function testDisabledBlockSetsEmptyString(): void
    {
        $block = $this->makeBlock('snippet', false);
        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn($block);
        $this->producerPool->expects(self::never())->method('get');

        $context = [];
        $this->action->execute($context, ['content_block_id' => 5]);

        self::assertSame('', $context['dynamic_content_html']);
    }

    public function testResolvedProducerHtmlIsWrittenToDefaultOutputKey(): void
    {
        $block = $this->makeBlock('snippet', true);
        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn($block);

        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects(self::once())->method('render')->with($block)->willReturn('<p>Hello</p>');
        $this->producerPool->expects(self::once())->method('get')->with('snippet')->willReturn($producer);

        $context = [];
        $this->action->execute($context, ['content_block_id' => 5]);

        self::assertSame('<p>Hello</p>', $context['dynamic_content_html']);
    }

    public function testMissingProducerForBlockTypeSetsEmptyString(): void
    {
        $block = $this->makeBlock('unknown_type', true);
        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn($block);
        $this->producerPool->expects(self::once())->method('get')->with('unknown_type')->willReturn(null);

        $context = [];
        $this->action->execute($context, ['content_block_id' => 5]);

        self::assertSame('', $context['dynamic_content_html']);
    }

    public function testCustomOutputKeyIsRespected(): void
    {
        $block = $this->makeBlock('snippet', true);
        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn($block);

        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects(self::once())->method('render')->willReturn('<p>Hi</p>');
        $this->producerPool->expects(self::once())->method('get')->willReturn($producer);

        $context = [];
        $this->action->execute($context, ['content_block_id' => 5, 'output_key' => 'custom_html_key']);

        self::assertSame('<p>Hi</p>', $context['custom_html_key']);
        self::assertArrayNotHasKey('dynamic_content_html', $context);
    }

    public function testCustomOutputKeyUsedForEmptyBlockIdCase(): void
    {
        $this->contentBlockRepository->expects(self::never())->method('getById');
        $this->producerPool->expects(self::never())->method('get');

        $context = [];
        $this->action->execute($context, ['output_key' => 'custom_html_key']);

        self::assertSame('', $context['custom_html_key']);
    }
}
