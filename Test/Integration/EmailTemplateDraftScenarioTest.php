<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion\CollectionFactory as EmailTemplateVersionCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB throughout, no mocks - proves the whole draft -> edit -> publish loop end to end the
 * way Test\Integration\EmailTemplateVersioningScenarioTest already does for restore. Email
 * template versioning is enabled for this test specifically to prove
 * Controller\Adminhtml\EmailTemplateDraft\Publish's save-through-the-real-core-resource-model
 * trick actually re-triggers Plugin\Email\SnapshotEmailTemplateVersion for free, exactly like
 * Controller\Adminhtml\EmailTemplateVersion\MassRestore already does for a restore.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the live
 * template, any leftover draft row, and any version snapshots this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/EmailTemplateDraftScenarioTest.php
 */
class EmailTemplateDraftScenarioTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_channels/email_template_versioning/enabled';

    private static ObjectManagerInterface $objectManager;

    private ?int $templateId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->delete(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function tearDown(): void
    {
        if ($this->templateId === null) {
            return;
        }

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_email_template_draft'),
            ['template_id = ?' => $this->templateId]
        );
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_email_template_version'),
            ['template_id = ?' => $this->templateId]
        );

        $templateFactory = self::$objectManager->get(MagentoTemplateFactory::class);
        $templateResource = self::$objectManager->get(MagentoTemplateResource::class);
        /** @var MagentoTemplate $template */
        $template = $templateFactory->create();
        $templateResource->load($template, $this->templateId);
        if ($template->getId()) {
            $templateResource->delete($template);
        }
        $this->templateId = null;
    }

    public function testDraftEditAndPublishRoundTrip(): void
    {
        $templateCode = 'Ordo integration test template ' . uniqid('', true);
        $template = $this->createTemplate($templateCode, 'Original subject', 'Original body');

        // NewAction's own logic: copy the live template's current content into a brand-new draft.
        $draftFactory = self::$objectManager->get(EmailTemplateDraftFactory::class);
        $draftResource = self::$objectManager->get(EmailTemplateDraftResource::class);

        /** @var EmailTemplateDraft $draft */
        $draft = $draftFactory->create();
        $draft->setTemplateId($this->templateId);
        $draft->setTemplateCode($templateCode);
        $draft->setTemplateSubject((string) $template->getData('template_subject'));
        $draft->setTemplateText((string) $template->getData('template_text'));
        $draftResource->save($draft);

        self::assertSame('Original subject', $draft->getTemplateSubject(), 'The draft must start as a copy of the live content.');

        // Save's own logic: only the draft row changes, the live template is untouched.
        $draft->setTemplateSubject('Edited subject');
        $draft->setTemplateText('Edited body');
        $draftResource->save($draft);

        $templateResource = self::$objectManager->get(MagentoTemplateResource::class);
        $liveBeforePublish = self::$objectManager->get(MagentoTemplateFactory::class)->create();
        $templateResource->load($liveBeforePublish, $this->templateId);
        self::assertSame(
            'Original subject',
            $liveBeforePublish->getData('template_subject'),
            'Editing a draft must never touch the live template send_email reads.'
        );

        // Publish's own logic: copy the draft onto the live row through the real core resource
        // model, then delete the draft.
        $liveBeforePublish->setData('template_subject', $draft->getTemplateSubject());
        $liveBeforePublish->setData('template_text', $draft->getTemplateText());
        $liveBeforePublish->setData('template_styles', $draft->getTemplateStyles());
        $templateResource->save($liveBeforePublish);
        $draftResource->delete($draft);

        $liveAfterPublish = self::$objectManager->get(MagentoTemplateFactory::class)->create();
        $templateResource->load($liveAfterPublish, $this->templateId);
        self::assertSame('Edited subject', $liveAfterPublish->getData('template_subject'), 'Publish must overwrite the live template.');
        self::assertSame('Edited body', $liveAfterPublish->getData('template_text'));

        $reloadedDraft = $draftFactory->create();
        $draftResource->load($reloadedDraft, $draft->getEntityId());
        self::assertNull($reloadedDraft->getEntityId(), 'Publishing must delete the draft row.');

        $versions = self::$objectManager->get(EmailTemplateVersionCollectionFactory::class)->create();
        $versions->addFieldToFilter('template_id', $this->templateId);
        $versions->setOrder('entity_id', 'ASC');
        $versionItems = array_values($versions->getItems());

        self::assertCount(2, $versionItems, 'The initial save and the publish-save must each snapshot.');
        self::assertSame(
            'Original subject',
            $versionItems[0]->getTemplateSubject(),
            'The pre-publish state must be snapshotted before being overwritten - this is the free "before publish" history point.'
        );
        self::assertSame('Edited subject', $versionItems[1]->getTemplateSubject());
    }

    private function createTemplate(string $templateCode, string $subject, string $text): MagentoTemplate
    {
        $templateFactory = self::$objectManager->get(MagentoTemplateFactory::class);
        $templateResource = self::$objectManager->get(MagentoTemplateResource::class);

        /** @var MagentoTemplate $template */
        $template = $templateFactory->create();
        $template->setData('template_code', $templateCode);
        $template->setData('template_type', \Magento\Framework\App\TemplateTypesInterface::TYPE_HTML);
        $template->setData('template_subject', $subject);
        $template->setData('template_text', $text);
        $templateResource->save($template);

        $this->templateId = (int) $template->getId();

        return $template;
    }
}
