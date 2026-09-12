<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\MessageLog;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\MessageLog\MassDelete;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedMessageLogEntry(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $entryA = $this->createStub(MessageLog::class);
        $entryB = $this->createStub(MessageLog::class);

        $collection = $this->makeRealCollection(MessageLogCollection::class, 'ordo_message_log');
        $collection->addItem($entryA);
        $collection->addItem($entryB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $messageLogCollectionFactory = $this->createStub(MessageLogCollectionFactory::class);
        $messageLogCollectionFactory->method('create')->willReturn($this->createStub(MessageLogCollection::class));

        $messageLogResource = $this->createMock(MessageLogResource::class);
        $messageLogResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 message log entry(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $messageLogCollectionFactory, $messageLogResource);
        $controller->execute();
    }
}
