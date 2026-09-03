<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\Save;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private ContentBlockFactory $contentBlockFactory;
    private ContentBlockResource $contentBlockResource;

    protected function setUp(): void
    {
        $this->contentBlockFactory = $this->createMock(ContentBlockFactory::class);
        $this->contentBlockResource = $this->createMock(ContentBlockResource::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->contentBlockFactory,
            $this->contentBlockResource
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->contentBlockFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewSnippetContentBlockAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'identifier' => 'welcome',
            'name' => 'Welcome snippet',
            'type' => 'snippet',
            'enabled' => '1',
            'html' => '<p>Hello</p>',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(7);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $this->contentBlockResource->expects(self::never())->method('load');
        $this->contentBlockResource->expects(self::once())->method('save')->with($contentBlock);

        $contentBlock->expects(self::once())->method('setConfigArray')->with(['html' => '<p>Hello</p>']);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsRssConfigWithIntegerItemCount(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'identifier' => 'blog',
            'name' => 'Blog feed',
            'type' => 'rss',
            'feed_url' => 'https://example.test/feed.xml',
            'item_count' => '3',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(1);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $contentBlock->expects(self::once())->method('setConfigArray')->with([
            'feed_url' => 'https://example.test/feed.xml',
            'item_count' => 3,
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsProductFeedConfig(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'identifier' => 'related',
            'name' => 'Related products',
            'type' => 'product_feed',
            'source' => 'rule',
            'category_id' => 12,
            'rule_id' => 4,
            'item_count' => '6',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(1);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $contentBlock->expects(self::once())->method('setConfigArray')->with([
            'source' => 'rule',
            'category_id' => 12,
            'rule_id' => 4,
            'item_count' => 6,
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsEmptyConfigForUnknownType(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'identifier' => 'mystery',
            'name' => 'Mystery block',
            'type' => 'not_a_real_type',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(1);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $contentBlock->expects(self::once())->method('setConfigArray')->with([]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDropsStaleConfigKeysWhenSwitchingType(): void
    {
        // An existing rss block is re-saved with type switched to snippet — buildConfig() must
        // only ever emit snippet's own keys, not carry over rss's feed_url/item_count.
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 3,
            'identifier' => 'blog',
            'name' => 'Blog feed',
            'type' => 'snippet',
            'html' => '<p>Now static</p>',
            // Stale fields a form resubmit could still include:
            'feed_url' => 'https://example.test/feed.xml',
            'item_count' => '3',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(3);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $this->contentBlockResource->expects(self::once())->method('load')->with($contentBlock, 3);

        $contentBlock->expects(self::once())->method('setConfigArray')->with(['html' => '<p>Now static</p>']);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingContentBlockBeforeUpdating(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 3,
            'identifier' => 'blog',
            'name' => 'Blog feed',
            'type' => 'rss',
            'feed_url' => 'https://example.test/feed.xml',
            'item_count' => '5',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $contentBlock = $this->createMock(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(3);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);

        $this->contentBlockResource->expects(self::once())->method('load')->with($contentBlock, 3);
        $this->contentBlockResource->expects(self::once())->method('save')->with($contentBlock);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'type' => 'snippet']);

        $contentBlock = $this->createStub(ContentBlock::class);
        $this->contentBlockFactory->method('create')->willReturn($contentBlock);
        $this->contentBlockResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
