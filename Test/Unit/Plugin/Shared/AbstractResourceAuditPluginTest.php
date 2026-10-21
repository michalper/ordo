<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Shared;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Plugin\Shared\AbstractResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AbstractResourceAuditPluginTest extends TestCase
{
    private const array AUDITED_FIELDS = ['name', 'identifier', 'type', 'enabled'];

    private function makePlugin(Recorder $recorder): AbstractResourceAuditPlugin
    {
        $auditedFields = self::AUDITED_FIELDS;

        return new class ($recorder, $auditedFields) extends AbstractResourceAuditPlugin {
            /** @param string[] $auditedFields */
            public function __construct(Recorder $recorder, private readonly array $auditedFieldsFixture)
            {
                parent::__construct($recorder);
            }

            protected function entityType(): string
            {
                return 'content_block';
            }

            protected function auditedFields(): array
            {
                return $this->auditedFieldsFixture;
            }
        };
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(AbstractModel::class);
        $subject = $this->createStub(AbstractDb::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $result = $this->makePlugin($recorder)->aroundSave($subject, fn () => $subject, $model);

        self::assertSame($subject, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(AbstractModel::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(AbstractDb::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('content_block', 0, AdminActionLog::ACTION_CREATE, null);

        $this->makePlugin($recorder)->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(AbstractModel::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(AbstractDb::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, self::AUDITED_FIELDS)
            ->willReturn(['name' => ['Old', 'New']]);
        $recorder->expects(self::once())->method('record')
            ->with('content_block', 7, AdminActionLog::ACTION_UPDATE, ['name' => ['Old', 'New']]);

        $this->makePlugin($recorder)->aroundSave($subject, fn () => $subject, $model);
    }

    /**
     * Regression test for exactly what `setup:di:compile` caught and a unit run never would:
     * each entity's di.xml plugin registration must name a real, loadable class — not a
     * virtualType (Magento's plugin-list compiler never resolves one there, unlike a
     * ui_component button's `class` attribute — see docs/adr/0009's Consequences section and
     * docs/CHANGELOG.md). Every registered class must also actually extend this shared base and
     * declare its own entity_type/audited-fields, so the dedup is real, not just by convention.
     */
    public function testEveryAuditedEntityIsWiredToARealSubclassWithItsOwnFields(): void
    {
        $expected = [
            'Ordo\Automation\Plugin\ContentBlock\ContentBlockResourceAuditPlugin' =>
                ['content_block', ['name', 'identifier', 'type', 'enabled']],
            'Ordo\Automation\Plugin\FreeGiftOffer\FreeGiftOfferResourceAuditPlugin' =>
                ['free_gift_offer', ['name', 'enabled']],
            'Ordo\Automation\Plugin\ScoreRule\ScoreRuleResourceAuditPlugin' =>
                ['score_rule', ['attribute_code', 'operator', 'value', 'points', 'enabled']],
            'Ordo\Automation\Plugin\AdAudience\AdAudienceResourceAuditPlugin' =>
                ['ad_audience', ['name', 'segment_id', 'platform', 'enabled']],
            'Ordo\Automation\Plugin\WhatsAppTemplate\WhatsAppTemplateResourceAuditPlugin' =>
                ['whatsapp_template', ['name', 'meta_template_name', 'category', 'language', 'body_text', 'status']],
        ];

        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/di.xml');
        self::assertNotFalse($xml);

        $found = [];
        foreach ($xml->type as $type) {
            foreach ($type->plugin ?? [] as $plugin) {
                $pluginClass = (string) $plugin['type'];
                if (!isset($expected[$pluginClass])) {
                    continue;
                }

                self::assertTrue(class_exists($pluginClass), $pluginClass . ' must be a real class');
                self::assertTrue(
                    is_subclass_of($pluginClass, AbstractResourceAuditPlugin::class),
                    $pluginClass . ' must extend AbstractResourceAuditPlugin'
                );

                $recorder = $this->createStub(Recorder::class);
                $recorder->method('hasLoggedInAdmin')->willReturn(true);
                $recorder->method('diffFields')->willReturn([]);
                $captured = [];
                $recorder->method('record')->willReturnCallback(
                    function (string $entityType, int $id, string $action, ?array $diff) use (&$captured): void {
                        $captured = [$entityType];
                    }
                );

                $model = $this->createStub(AbstractModel::class);
                $model->method('getId')->willReturn(1);
                $subject = $this->createStub(AbstractDb::class);

                $plugin = new $pluginClass($recorder);
                $plugin->aroundSave($subject, fn () => $subject, $model);

                self::assertSame($expected[$pluginClass][0], $captured[0] ?? null, $pluginClass);

                // auditedFields() is protected — exercised indirectly via diffFields()'s args,
                // asserted with full Recorder mock expectations rather than the loose stub above.
                $strictRecorder = $this->createMock(Recorder::class);
                $strictRecorder->method('hasLoggedInAdmin')->willReturn(true);
                $strictRecorder->expects(self::once())->method('diffFields')
                    ->with($model, $expected[$pluginClass][1])
                    ->willReturn([]);
                $strictRecorder->method('record');

                (new $pluginClass($strictRecorder))->aroundSave($subject, fn () => $subject, $model);

                $found[] = $pluginClass;
            }
        }

        self::assertCount(count($expected), $found, 'every audited entity must be wired to its plugin class');
    }
}
