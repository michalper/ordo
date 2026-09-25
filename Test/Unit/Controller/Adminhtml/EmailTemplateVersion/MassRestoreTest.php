<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateVersion;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateVersion\MassRestore;
use Ordo\Automation\Model\EmailTemplateVersion;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion\Collection as EmailTemplateVersionCollection;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion\CollectionFactory as EmailTemplateVersionCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassRestoreTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRestoresEachSelectedVersionOntoItsLiveTemplate(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $version = $this->createStub(EmailTemplateVersion::class);
        $version->method('getTemplateId')->willReturn(7);
        $version->method('getTemplateCode')->willReturn('welcome_email');
        $version->method('getTemplateSubject')->willReturn('Welcome back!');
        $version->method('getTemplateText')->willReturn('<p>Hello {{var customer_name}}</p>');
        $version->method('getTemplateStyles')->willReturn('.foo { color: red; }');

        $collection = $this->makeRealCollection(EmailTemplateVersionCollection::class, 'ordo_email_template_version');
        $collection->addItem($version);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $emailTemplateVersionCollectionFactory = $this->createStub(EmailTemplateVersionCollectionFactory::class);
        $emailTemplateVersionCollectionFactory->method('create')
            ->willReturn($this->createStub(EmailTemplateVersionCollection::class));

        $liveTemplate = $this->createMock(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);
        $liveTemplate->method('setData');

        $magentoTemplateFactory = $this->createStub(MagentoTemplateFactory::class);
        $magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->expects(self::once())->method('load')->with($liveTemplate, 7);
        $magentoTemplateResource->expects(self::once())->method('save')->with($liveTemplate);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('Restored %1 template version(s).', 1));

        $controller = new MassRestore(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateFactory,
            $magentoTemplateResource
        );
        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsVersionWhoseLiveTemplateNoLongerExists(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $version = $this->createStub(EmailTemplateVersion::class);
        $version->method('getTemplateId')->willReturn(99);

        $collection = $this->makeRealCollection(EmailTemplateVersionCollection::class, 'ordo_email_template_version');
        $collection->addItem($version);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $emailTemplateVersionCollectionFactory = $this->createStub(EmailTemplateVersionCollectionFactory::class);
        $emailTemplateVersionCollectionFactory->method('create')
            ->willReturn($this->createStub(EmailTemplateVersionCollection::class));

        $missingTemplate = $this->createStub(MagentoTemplate::class);
        $missingTemplate->method('getId')->willReturn(null);

        $magentoTemplateFactory = $this->createStub(MagentoTemplateFactory::class);
        $magentoTemplateFactory->method('create')->willReturn($missingTemplate);

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->expects(self::never())->method('save');

        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = new MassRestore(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateFactory,
            $magentoTemplateResource
        );
        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReportsErrorWhenSaveThrows(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $version = $this->createStub(EmailTemplateVersion::class);
        $version->method('getTemplateId')->willReturn(7);
        $version->method('getTemplateCode')->willReturn('welcome_email');
        $version->method('getTemplateSubject')->willReturn('Welcome back!');
        $version->method('getTemplateText')->willReturn('<p>Hello</p>');
        $version->method('getTemplateStyles')->willReturn(null);

        $collection = $this->makeRealCollection(EmailTemplateVersionCollection::class, 'ordo_email_template_version');
        $collection->addItem($version);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $emailTemplateVersionCollectionFactory = $this->createStub(EmailTemplateVersionCollectionFactory::class);
        $emailTemplateVersionCollectionFactory->method('create')
            ->willReturn($this->createStub(EmailTemplateVersionCollection::class));

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);

        $magentoTemplateFactory = $this->createStub(MagentoTemplateFactory::class);
        $magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->method('save')->willThrowException(new \RuntimeException('db is on fire'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('Could not restore template "%1": %2', 'welcome_email', 'db is on fire'));
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = new MassRestore(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateFactory,
            $magentoTemplateResource
        );
        $controller->execute();
    }
}
