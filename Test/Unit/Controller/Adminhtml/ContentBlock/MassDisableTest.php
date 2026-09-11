<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\MassDisable;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection as ContentBlockCollection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedContentBlock(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $block = $this->createMock(ContentBlock::class);
        $block->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(ContentBlockCollection::class, 'ordo_content_block');
        $collection->addItem($block);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $contentBlockCollectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $contentBlockCollectionFactory->method('create')->willReturn($this->createStub(ContentBlockCollection::class));

        $contentBlockResource = $this->createMock(ContentBlockResource::class);
        $contentBlockResource->expects(self::once())->method('save')->with($block);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 content block(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $contentBlockCollectionFactory, $contentBlockResource);
        $controller->execute();
    }
}
