<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Shared;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Plugin\Shared\ResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ResourceAuditPluginTest extends TestCase
{
    private const array AUDITED_FIELDS = ['name', 'identifier', 'type', 'enabled'];

    private function makePlugin(Recorder $recorder): ResourceAuditPlugin
    {
        return new ResourceAuditPlugin($recorder, 'content_block', self::AUDITED_FIELDS);
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
     * The per-entity wiring moved from 5 PHP classes into virtualTypes — this parses
     * etc/adminhtml/di.xml the same way ButtonWiringTest does and pins each entity's
     * entity_type label and audited-field list, so a wiring typo can't silently stop (or
     * mislabel) an entity's audit trail.
     */
    public function testEveryAuditedEntityIsWiredAsAVirtualTypeWithItsOwnFields(): void
    {
        $expected = [
            'OrdoAutomationContentBlockResourceAuditPlugin' =>
                ['content_block', ['name', 'identifier', 'type', 'enabled']],
            'OrdoAutomationFreeGiftOfferResourceAuditPlugin' =>
                ['free_gift_offer', ['name', 'enabled']],
            'OrdoAutomationScoreRuleResourceAuditPlugin' =>
                ['score_rule', ['attribute_code', 'operator', 'value', 'points', 'enabled']],
            'OrdoAutomationAdAudienceResourceAuditPlugin' =>
                ['ad_audience', ['name', 'segment_id', 'platform', 'enabled']],
            'OrdoAutomationWhatsAppTemplateResourceAuditPlugin' =>
                ['whatsapp_template', ['name', 'meta_template_name', 'category', 'language', 'body_text', 'status']],
        ];

        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/di.xml');
        self::assertNotFalse($xml);

        $found = [];
        foreach ($xml->virtualType as $virtualType) {
            $name = (string) $virtualType['name'];
            if (!isset($expected[$name])) {
                continue;
            }

            self::assertSame(ResourceAuditPlugin::class, (string) $virtualType['type'], $name);

            $entityType = null;
            $fields = [];
            foreach ($virtualType->arguments->argument as $argument) {
                if ((string) $argument['name'] === 'entityType') {
                    $entityType = (string) $argument;
                }
                if ((string) $argument['name'] === 'auditedFields') {
                    foreach ($argument->item as $item) {
                        $fields[] = (string) $item;
                    }
                }
            }

            self::assertSame($expected[$name][0], $entityType, $name);
            self::assertSame($expected[$name][1], $fields, $name);
            $found[] = $name;
        }

        self::assertCount(count($expected), $found, 'every audited entity must have its virtualType');
    }
}
