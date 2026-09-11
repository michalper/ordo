<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\Segment\SegmentImporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SegmentImporterTest extends TestCase
{
    private SegmentFactory $segmentFactory;
    private SegmentResource $segmentResource;
    private SegmentConditionFactory $segmentConditionFactory;
    private SegmentConditionResource $segmentConditionResource;
    private ConditionPool $conditionPool;
    private SegmentImporter $importer;
    /** @var array<int, SegmentCondition> */
    private array $savedConditions;

    protected function setUp(): void
    {
        $modelResource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $modelResource->method('getIdFieldName')->willReturn('entity_id');

        $this->segmentFactory = $this->createStub(SegmentFactory::class);
        $this->segmentFactory->method('create')->willReturnCallback(fn () => new Segment(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $modelResource
        ));

        $this->segmentResource = $this->createStub(SegmentResource::class);
        $this->segmentResource->method('save')->willReturnCallback(function (Segment $segment) {
            $segment->setData('entity_id', 5);
            return $this->segmentResource;
        });

        $this->savedConditions = [];
        $this->segmentConditionFactory = $this->createStub(SegmentConditionFactory::class);
        $this->segmentConditionFactory->method('create')->willReturnCallback(fn () => new SegmentCondition(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $modelResource
        ));

        $this->segmentConditionResource = $this->createStub(SegmentConditionResource::class);
        $this->segmentConditionResource->method('save')->willReturnCallback(
            function (SegmentCondition $condition) {
                $this->savedConditions[] = $condition;
                return $this->segmentConditionResource;
            }
        );

        $this->conditionPool = $this->createStub(ConditionPool::class);
        $this->conditionPool->method('get')->willReturnMap([
            ['tag', $this->createStub(ConditionInterface::class)],
        ]);

        $this->importer = new SegmentImporter(
            $this->segmentFactory,
            $this->segmentResource,
            $this->segmentConditionFactory,
            $this->segmentConditionResource,
            $this->conditionPool
        );
    }

    public function testImportRejectsAWrongExportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['export_type' => 'ordo_campaign', 'name' => 'x']);
    }

    public function testImportRejectsAMissingExportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['name' => 'x']);
    }

    public function testImportRejectsAMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['export_type' => 'ordo_segment', 'name' => '   ']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportCreatesTheSegmentWithItsFields(): void
    {
        $segment = $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'enabled' => true,
            'condition_logic' => 'any',
            'conditions' => [],
        ]);

        self::assertSame('VIPs', $segment->getName());
        self::assertTrue($segment->isEnabled());
        self::assertSame('any', $segment->getConditionLogic());
        self::assertSame(5, $segment->getEntityId());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDefaultsConditionLogicToAll(): void
    {
        $segment = $this->importer->import(['export_type' => 'ordo_segment', 'name' => 'VIPs']);

        self::assertSame('all', $segment->getConditionLogic());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportSavesKnownConditionTypesInOrder(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'conditions' => [
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
                ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]],
            ],
        ]);

        self::assertCount(2, $this->savedConditions);
        self::assertSame('tag', $this->savedConditions[0]->getType());
        self::assertSame(['tag' => 'vip'], $this->savedConditions[0]->getParams());
        self::assertSame(0, $this->savedConditions[0]->getSortOrder());
        self::assertSame('group', $this->savedConditions[1]->getType());
        self::assertSame(1, $this->savedConditions[1]->getSortOrder());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAnUnknownConditionType(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'conditions' => [
                ['type' => 'this_type_does_not_exist', 'params' => []],
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ],
        ]);

        self::assertCount(1, $this->savedConditions);
        self::assertSame('tag', $this->savedConditions[0]->getType());
        // Re-sequenced from 0, not left at its original index 1 - a dropped row must not leave
        // a gap in the surviving rows' sort_order.
        self::assertSame(0, $this->savedConditions[0]->getSortOrder());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAMalformedConditionRow(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'conditions' => [
                'not-an-array',
                ['type' => ''],
                ['no_type_key' => true],
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ],
        ]);

        self::assertCount(1, $this->savedConditions);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportCapsConditionsAtTheMaximum(): void
    {
        $conditions = array_fill(0, 15, ['type' => 'tag', 'params' => ['tag' => 'vip']]);

        $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'conditions' => $conditions,
        ]);

        self::assertCount(10, $this->savedConditions);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportIgnoresANonArrayConditionsValue(): void
    {
        $segment = $this->importer->import([
            'export_type' => 'ordo_segment',
            'name' => 'VIPs',
            'conditions' => 'not-an-array',
        ]);

        self::assertSame('VIPs', $segment->getName());
        self::assertCount(0, $this->savedConditions);
    }
}
