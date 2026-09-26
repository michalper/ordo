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
use Ordo\Automation\Model\EmailTemplateVersion;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion\CollectionFactory as EmailTemplateVersionCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Closes one of the five remaining SCENARIOS.md gaps: email template versioning
 * (Plugin\Email\SnapshotEmailTemplateVersion, Controller\Adminhtml\EmailTemplateVersion\*) was
 * unit-tested only, never proven against a real Magento\Email\Model\Template save. Real DI/DB
 * throughout - a real Magento email template is saved through its own real resource model twice
 * (proving the plugin fires on every real save, not just a simulated one), then this test does
 * exactly what Controller\Adminhtml\EmailTemplateVersion\MassRestore's own execute() does
 * (copy a snapshot's subject/text/styles back onto the live template and save it through the
 * same real resource model) - the same real side effect that controller produces, without also
 * having to drive Magento's own admin email-template form (a CodeMirror-backed textarea) through
 * a browser for what is otherwise a pure data-layer round-trip. The restore's own "this creates a
 * fresh snapshot too" behavior is asserted directly off the real snapshot table.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the
 * template (Magento's own resource model) and every snapshot row this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/EmailTemplateVersioningScenarioTest.php
 */
class EmailTemplateVersioningScenarioTest extends TestCase
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
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        if ($this->templateId !== null) {
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
    }

    public function testEverySaveSnapshotsTheTemplatesCurrentContent(): void
    {
        $templateCode = 'Ordo integration test template ' . uniqid('', true);
        $template = $this->createTemplate($templateCode, 'Original subject', 'Original body');

        $versionsAfterCreate = $this->loadVersionsOrderedByEntityId();
        self::assertCount(1, $versionsAfterCreate, 'The initial save must already be snapshotted.');
        self::assertSame('Original subject', $versionsAfterCreate[0]->getTemplateSubject());
        self::assertSame('Original body', $versionsAfterCreate[0]->getTemplateText());

        $templateResource = self::$objectManager->get(MagentoTemplateResource::class);
        $template->setData('template_subject', 'Edited subject');
        $template->setData('template_text', 'Edited body');
        $templateResource->save($template);

        $versionsAfterEdit = $this->loadVersionsOrderedByEntityId();
        self::assertCount(2, $versionsAfterEdit, 'A second real save must append a second snapshot.');
        self::assertSame('Original subject', $versionsAfterEdit[0]->getTemplateSubject(), 'The first snapshot is untouched.');
        self::assertSame('Edited subject', $versionsAfterEdit[1]->getTemplateSubject());
        self::assertSame('Edited body', $versionsAfterEdit[1]->getTemplateText());
    }

    public function testRestoringASnapshotOverwritesTheLiveTemplateAndSnapshotsAgain(): void
    {
        $templateCode = 'Ordo integration test template ' . uniqid('', true);
        $template = $this->createTemplate($templateCode, 'v1 subject', 'v1 body');

        $templateResource = self::$objectManager->get(MagentoTemplateResource::class);
        $template->setData('template_subject', 'v2 subject');
        $template->setData('template_text', 'v2 body');
        $templateResource->save($template);

        $versionsBeforeRestore = $this->loadVersionsOrderedByEntityId();
        self::assertCount(2, $versionsBeforeRestore);
        $v1 = $versionsBeforeRestore[0];
        self::assertSame('v1 subject', $v1->getTemplateSubject());

        // Same restore logic MassRestore::execute() runs per selected row: copy the snapshot's
        // own subject/text/styles back onto the live template and save through the real
        // resource model - which itself re-triggers the snapshot plugin, same as that
        // controller's own docblock describes ("a restore is never a dead end, it's just
        // another save").
        $template->setData('template_subject', $v1->getTemplateSubject());
        $template->setData('template_text', $v1->getTemplateText());
        $template->setData('template_styles', $v1->getTemplateStyles());
        $templateResource->save($template);

        $reloadedTemplate = self::$objectManager->get(MagentoTemplateFactory::class)->create();
        $templateResource->load($reloadedTemplate, $this->templateId);
        self::assertSame('v1 subject', $reloadedTemplate->getData('template_subject'), 'The live template must be reverted.');
        self::assertSame('v1 body', $reloadedTemplate->getData('template_text'));

        $versionsAfterRestore = $this->loadVersionsOrderedByEntityId();
        self::assertCount(3, $versionsAfterRestore, 'Restoring must itself create a fresh snapshot, not just overwrite in place.');
        self::assertSame('v1 subject', $versionsAfterRestore[2]->getTemplateSubject());
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

    /**
     * @return EmailTemplateVersion[]
     */
    private function loadVersionsOrderedByEntityId(): array
    {
        $collection = self::$objectManager->get(EmailTemplateVersionCollectionFactory::class)->create();
        $collection->addFieldToFilter('template_id', $this->templateId);
        $collection->setOrder('entity_id', 'ASC');

        return array_values($collection->getItems());
    }
}
