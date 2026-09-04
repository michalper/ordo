<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\RefreshRss;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\RssFetcher;
use Ordo\Automation\Model\ContentBlockRepository;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RefreshRssTest extends AbstractAdminActionTestCase
{
    private ContentBlockRepository $contentBlockRepository;
    private RssFetcher $rssFetcher;
    private JsonFactory $jsonFactory;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->contentBlockRepository = $this->createMock(ContentBlockRepository::class);
        $this->rssFetcher = $this->createMock(RssFetcher::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();

        $this->jsonFactory = $this->createStub(JsonFactory::class);
        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): RefreshRss
    {
        return new RefreshRss(
            $this->makeContext(),
            $this->contentBlockRepository,
            $this->rssFetcher,
            $this->jsonFactory
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFailureWithoutRepositoryCallWhenIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', null]]);

        $this->contentBlockRepository->expects(self::never())->method('getById');
        $this->rssFetcher->expects(self::never())->method('fetch');

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => false,
            'message' => 'Content block not found.',
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFailureWithoutRepositoryCallWhenIdInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', '0']]);

        $this->contentBlockRepository->expects(self::never())->method('getById');
        $this->rssFetcher->expects(self::never())->method('fetch');

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => false,
            'message' => 'Content block not found.',
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFailureWhenBlockNotFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', '5']]);

        $this->contentBlockRepository->expects(self::once())->method('getById')->with(5)->willReturn(null);
        $this->rssFetcher->expects(self::never())->method('fetch');

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => false,
            'message' => 'Content block not found.',
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFailureWhenBlockIsNotRss(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', '5']]);

        $block = $this->createStub(ContentBlock::class);
        $block->method('getType')->willReturn('snippet');
        $this->contentBlockRepository->method('getById')->willReturn($block);

        $this->rssFetcher->expects(self::never())->method('fetch');

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => false,
            'message' => 'This content block is not an RSS feed.',
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSuccessOnSuccessfulFetch(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', '5']]);

        $block = $this->createStub(ContentBlock::class);
        $block->method('getType')->willReturn('rss');
        $this->contentBlockRepository->method('getById')->willReturn($block);

        $this->rssFetcher->expects(self::once())->method('fetch')->with($block);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => true,
            'message' => 'Feed refreshed.',
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFailureWhenFetchThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['content_block_id', '5']]);

        $block = $this->createStub(ContentBlock::class);
        $block->method('getType')->willReturn('rss');
        $this->contentBlockRepository->method('getById')->willReturn($block);

        $this->rssFetcher->method('fetch')->willThrowException(new \RuntimeException('feed unreachable'));

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'success' => false,
            'message' => 'Refresh failed: feed unreachable',
        ]);

        $controller->execute();
    }
}
