<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate\MassDelete;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\Collection as WhatsAppTemplateCollection;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedWhatsAppTemplate(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $templateA = $this->createStub(WhatsAppTemplate::class);
        $templateB = $this->createStub(WhatsAppTemplate::class);

        $collection = $this->makeRealCollection(WhatsAppTemplateCollection::class, 'ordo_whatsapp_template');
        $collection->addItem($templateA);
        $collection->addItem($templateB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $whatsAppTemplateCollectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $whatsAppTemplateCollectionFactory->method('create')->willReturn($this->createStub(WhatsAppTemplateCollection::class));

        $whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
        $whatsAppTemplateResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 WhatsApp template(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $whatsAppTemplateCollectionFactory, $whatsAppTemplateResource);
        $controller->execute();
    }
}
