<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Block\Template\Context;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\ResourceModel\Template\Collection as MagentoTemplateCollection;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as MagentoTemplateCollectionFactory;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\EmailTemplateDraft\Chooser;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\Collection as EmailTemplateDraftCollection;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ChooserTest extends TestCase
{
    use MakesRealCollectionTrait;

    private function makeTemplate(int $id, string $code): MagentoTemplate
    {
        $template = $this->createStub(MagentoTemplate::class);
        $template->method('getId')->willReturn($id);
        $template->method('getData')->willReturnMap([['template_code', null, $code]]);

        return $template;
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetUndraftedTemplatesExcludesTemplatesWithAnOpenDraft(): void
    {
        // Magento\Email\Model\ResourceModel\Template\Collection::_construct() calls
        // $this->getMainTable() (-> getResource()) before AbstractCollection's own constructor
        // has assigned the real $resource argument - getResource() falls back to
        // ObjectManager::getInstance()->create(...) for that one moment, so it needs a stub
        // resource with getMainTable() here too, even though the real stub resource we pass in
        // below is what the collection actually ends up using afterwards.
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        $fallbackTemplateResource = $this->createStub(MagentoTemplateResource::class);
        $fallbackTemplateResource->method('getMainTable')->willReturn('email_template');
        $objectManager->method('create')->willReturn($fallbackTemplateResource);
        ObjectManager::setInstance($objectManager);

        $templateCollection = $this->makeRealCollection(MagentoTemplateCollection::class, 'email_template');
        $templateCollection->addItem($this->makeTemplate(1, 'welcome_email'));
        $templateCollection->addItem($this->makeTemplate(2, 'ordo_campaign_generic'));

        $magentoTemplateCollectionFactory = $this->createStub(MagentoTemplateCollectionFactory::class);
        $magentoTemplateCollectionFactory->method('create')->willReturn($templateCollection);

        $draftedTemplate = $this->createStub(EmailTemplateDraft::class);
        $draftedTemplate->method('getData')->willReturnMap([['template_id', null, 2]]);

        $draftCollection = $this->makeRealCollection(EmailTemplateDraftCollection::class, 'ordo_email_template_draft');
        $draftCollection->addItem($draftedTemplate);

        $draftCollectionFactory = $this->createStub(EmailTemplateDraftCollectionFactory::class);
        $draftCollectionFactory->method('create')->willReturn($draftCollection);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->createStub(UrlInterface::class));

        $block = new Chooser($context, $magentoTemplateCollectionFactory, $draftCollectionFactory);

        self::assertSame([['id' => 1, 'code' => 'welcome_email']], $block->getUndraftedTemplates());
    }

    public function testGetNewDraftUrlBuildsTheNewActionRoute(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('*/*/new')
            ->willReturn('https://admin.example.test/emailtemplatedraft/new');

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $block = new Chooser(
            $context,
            $this->createStub(MagentoTemplateCollectionFactory::class),
            $this->createStub(EmailTemplateDraftCollectionFactory::class)
        );

        self::assertSame('https://admin.example.test/emailtemplatedraft/new', $block->getNewDraftUrl());
    }
}
