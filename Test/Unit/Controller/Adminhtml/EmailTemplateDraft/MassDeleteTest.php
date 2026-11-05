<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\MassDelete;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\Collection as EmailTemplateDraftCollection;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedDraft(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draftA = $this->createStub(EmailTemplateDraft::class);
        $draftB = $this->createStub(EmailTemplateDraft::class);

        $collection = $this->makeRealCollection(EmailTemplateDraftCollection::class, 'ordo_email_template_draft');
        $collection->addItem($draftA);
        $collection->addItem($draftB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $draftCollectionFactory = $this->createStub(EmailTemplateDraftCollectionFactory::class);
        $draftCollectionFactory->method('create')->willReturn($this->createStub(EmailTemplateDraftCollection::class));

        $draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $draftResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 email template draft(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $draftCollectionFactory, $draftResource);
        $controller->execute();
    }
}
