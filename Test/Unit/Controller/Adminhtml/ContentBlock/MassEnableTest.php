<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\MassEnable;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection as ContentBlockCollection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassEnableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEnablesEverySelectedContentBlock(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $blockA = $this->createMock(ContentBlock::class);
        $blockA->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $blockB = $this->createMock(ContentBlock::class);
        $blockB->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $collection = $this->makeRealCollection(ContentBlockCollection::class, 'ordo_content_block');
        $collection->addItem($blockA);
        $collection->addItem($blockB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $contentBlockCollectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $contentBlockCollectionFactory->method('create')->willReturn($this->createStub(ContentBlockCollection::class));

        $contentBlockResource = $this->createMock(ContentBlockResource::class);
        $contentBlockResource->expects(self::exactly(2))->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 content block(s) have been enabled.', 2));

        $controller = new MassEnable($context, $filter, $contentBlockCollectionFactory, $contentBlockResource);
        $controller->execute();
    }
}
