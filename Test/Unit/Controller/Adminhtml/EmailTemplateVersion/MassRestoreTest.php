<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateVersion;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\ResourceModel\Template\Collection as MagentoTemplateCollection;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Magento\Email\Model\Template as MagentoTemplate;
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

    /**
     * A plain stub, not makeRealCollection() - Magento\Email\Model\ResourceModel\Template\
     * Collection's own _construct() calls getMainTable(), which (unlike this module's own
     * collections) resolves its resource via ObjectManager::getInstance() before the real
     * constructor has a chance to assign the injected $resource - unavailable in this isolated
     * unit test environment.
     */
    private function makeTemplateCollection(MagentoTemplate ...$templates): MagentoTemplateCollection
    {
        $collection = $this->createStub(MagentoTemplateCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($templates));
        return $collection;
    }

    private function makeController(
        \Magento\Backend\App\Action\Context $context,
        Filter $filter,
        EmailTemplateVersionCollectionFactory $emailTemplateVersionCollectionFactory,
        MagentoTemplateCollectionFactory $magentoTemplateCollectionFactory,
        MagentoTemplateResource $magentoTemplateResource
    ): MassRestore {
        return new MassRestore(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateCollectionFactory,
            $magentoTemplateResource
        );
    }

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

        $templateCollection = $this->makeTemplateCollection($liveTemplate);
        $magentoTemplateCollectionFactory = $this->createMock(MagentoTemplateCollectionFactory::class);
        $magentoTemplateCollectionFactory->expects(self::once())->method('create')->willReturn($templateCollection);

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->expects(self::once())->method('save')->with($liveTemplate);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('Restored %1 template version(s).', 1));

        $controller = $this->makeController(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateCollectionFactory,
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

        // The batched load simply never returns a row for a template_id that no longer exists -
        // nothing to add to the (empty) template collection.
        $magentoTemplateCollectionFactory = $this->createStub(MagentoTemplateCollectionFactory::class);
        $magentoTemplateCollectionFactory->method('create')->willReturn($this->makeTemplateCollection());

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->expects(self::never())->method('save');

        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = $this->makeController(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateCollectionFactory,
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

        $magentoTemplateCollectionFactory = $this->createStub(MagentoTemplateCollectionFactory::class);
        $magentoTemplateCollectionFactory->method('create')->willReturn($this->makeTemplateCollection($liveTemplate));

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->method('save')->willThrowException(new \RuntimeException('db is on fire'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('Could not restore template "%1": %2', 'welcome_email', 'db is on fire'));
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = $this->makeController(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateCollectionFactory,
            $magentoTemplateResource
        );
        $controller->execute();
    }

    /**
     * Regression for a full-repo /simplify finding: two selected versions of the same template
     * used to each do their own MagentoTemplateResource::load() call. Must now share the one
     * already-batch-loaded model instance, and still save (and count) each row independently -
     * same "harmless, just redundant" behavior this class's own docblock already documents.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSharesOneLoadedTemplateAcrossTwoVersionsOfTheSameTemplate(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $firstVersion = $this->createStub(EmailTemplateVersion::class);
        $firstVersion->method('getTemplateId')->willReturn(7);
        $firstVersion->method('getTemplateCode')->willReturn('welcome_email');
        $firstVersion->method('getTemplateSubject')->willReturn('First subject');
        $firstVersion->method('getTemplateText')->willReturn('First text');
        $firstVersion->method('getTemplateStyles')->willReturn(null);

        $secondVersion = $this->createStub(EmailTemplateVersion::class);
        $secondVersion->method('getTemplateId')->willReturn(7);
        $secondVersion->method('getTemplateCode')->willReturn('welcome_email');
        $secondVersion->method('getTemplateSubject')->willReturn('Second subject');
        $secondVersion->method('getTemplateText')->willReturn('Second text');
        $secondVersion->method('getTemplateStyles')->willReturn(null);

        $collection = $this->makeRealCollection(EmailTemplateVersionCollection::class, 'ordo_email_template_version');
        $collection->addItem($firstVersion);
        $collection->addItem($secondVersion);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $emailTemplateVersionCollectionFactory = $this->createStub(EmailTemplateVersionCollectionFactory::class);
        $emailTemplateVersionCollectionFactory->method('create')
            ->willReturn($this->createStub(EmailTemplateVersionCollection::class));

        $liveTemplate = $this->createMock(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);
        $subjectsSet = [];
        $liveTemplate->method('setData')->willReturnCallback(
            function (string $key, $value) use (&$subjectsSet) {
                if ($key === 'template_subject') {
                    $subjectsSet[] = $value;
                }
            }
        );

        $magentoTemplateCollectionFactory = $this->createMock(MagentoTemplateCollectionFactory::class);
        $magentoTemplateCollectionFactory->expects(self::once())->method('create')
            ->willReturn($this->makeTemplateCollection($liveTemplate));

        $magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $magentoTemplateResource->expects(self::exactly(2))->method('save')->with($liveTemplate);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('Restored %1 template version(s).', 2));

        $controller = $this->makeController(
            $context,
            $filter,
            $emailTemplateVersionCollectionFactory,
            $magentoTemplateCollectionFactory,
            $magentoTemplateResource
        );
        $controller->execute();

        self::assertSame(['First subject', 'Second subject'], $subjectsSet);
    }

    /**
     * This restores straight onto the live core email_template row - Ordo_Automation::
     * email_template_versioning is a sibling ACL node, not a child of Magento_Email::template,
     * so _isAllowed() must require both, not just its own resource.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testIsAllowedRequiresBothTheOwnResourceAndMagentoEmailTemplate(): void
    {
        $controller = $this->makeController(
            $this->makeContext(),
            $this->createStub(Filter::class),
            $this->createStub(EmailTemplateVersionCollectionFactory::class),
            $this->createStub(MagentoTemplateCollectionFactory::class),
            $this->createStub(MagentoTemplateResource::class)
        );
        $isAllowed = new \ReflectionMethod(MassRestore::class, '_isAllowed');

        $this->authorization->method('isAllowed')->willReturnMap([
            ['Ordo_Automation::email_template_versioning', null, true],
            ['Magento_Email::template', null, false],
        ]);
        self::assertFalse($isAllowed->invoke($controller));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsAllowedPassesWhenBothResourcesAreGranted(): void
    {
        $controller = $this->makeController(
            $this->makeContext(),
            $this->createStub(Filter::class),
            $this->createStub(EmailTemplateVersionCollectionFactory::class),
            $this->createStub(MagentoTemplateCollectionFactory::class),
            $this->createStub(MagentoTemplateResource::class)
        );
        $isAllowed = new \ReflectionMethod(MassRestore::class, '_isAllowed');

        $this->authorization->method('isAllowed')->willReturn(true);
        self::assertTrue($isAllowed->invoke($controller));
    }
}
