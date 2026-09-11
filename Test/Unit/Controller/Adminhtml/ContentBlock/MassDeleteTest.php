<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\MassDelete;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection as ContentBlockCollection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedContentBlock(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $blockA = $this->createStub(ContentBlock::class);
        $blockB = $this->createStub(ContentBlock::class);

        $collection = $this->makeRealCollection(ContentBlockCollection::class, 'ordo_content_block');
        $collection->addItem($blockA);
        $collection->addItem($blockB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $contentBlockCollectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $contentBlockCollectionFactory->method('create')->willReturn($this->createStub(ContentBlockCollection::class));

        $contentBlockResource = $this->createMock(ContentBlockResource::class);
        $contentBlockResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 content block(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $contentBlockCollectionFactory, $contentBlockResource);
        $controller->execute();
    }
}
