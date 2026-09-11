<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignImporterTest extends TestCase
{
    private CampaignFactory $campaignFactory;
    private CampaignResource $campaignResource;
    private CampaignTriggerFactory $campaignTriggerFactory;
    private CampaignConditionFactory $campaignConditionFactory;
    private CampaignActionFactory $campaignActionFactory;
    private ConditionPool $conditionPool;
    private ActionPool $actionPool;
    private CampaignImporter $importer;
    /** @var array<int, CampaignTrigger> */
    private array $savedTriggers;
    /** @var array<int, CampaignCondition> */
    private array $savedConditions;
    /** @var array<int, CampaignAction> */
    private array $savedActions;

    protected function setUp(): void
    {
        $modelResource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $modelResource->method('getIdFieldName')->willReturn('entity_id');
        $context = $this->createStub(\Magento\Framework\Model\Context::class);
        $registry = $this->createStub(\Magento\Framework\Registry::class);

        $this->campaignFactory = $this->createStub(CampaignFactory::class);
        $this->campaignFactory->method('create')
            ->willReturnCallback(fn () => new Campaign($context, $registry, $modelResource));

        $this->campaignResource = $this->createStub(CampaignResource::class);
        $this->campaignResource->method('save')->willReturnCallback(function (Campaign $campaign) {
            $campaign->setData('entity_id', 9);
            return $this->campaignResource;
        });

        $this->savedTriggers = [];
        $this->campaignTriggerFactory = $this->createStub(CampaignTriggerFactory::class);
        $this->campaignTriggerFactory->method('create')
            ->willReturnCallback(fn () => new CampaignTrigger($context, $registry, $modelResource));
        $campaignTriggerResource = $this->createStub(CampaignTriggerResource::class);
        $campaignTriggerResource->method('save')->willReturnCallback(
            function (CampaignTrigger $trigger) use ($campaignTriggerResource) {
                $this->savedTriggers[] = $trigger;
                return $campaignTriggerResource;
            }
        );

        $this->savedConditions = [];
        $this->campaignConditionFactory = $this->createStub(CampaignConditionFactory::class);
        $this->campaignConditionFactory->method('create')
            ->willReturnCallback(fn () => new CampaignCondition($context, $registry, $modelResource));
        $campaignConditionResource = $this->createStub(CampaignConditionResource::class);
        $campaignConditionResource->method('save')->willReturnCallback(
            function (CampaignCondition $condition) use ($campaignConditionResource) {
                $this->savedConditions[] = $condition;
                return $campaignConditionResource;
            }
        );

        $this->savedActions = [];
        $this->campaignActionFactory = $this->createStub(CampaignActionFactory::class);
        $this->campaignActionFactory->method('create')
            ->willReturnCallback(fn () => new CampaignAction($context, $registry, $modelResource));
        $campaignActionResource = $this->createStub(CampaignActionResource::class);
        $campaignActionResource->method('save')->willReturnCallback(
            function (CampaignAction $action) use ($campaignActionResource) {
                $this->savedActions[] = $action;
                return $campaignActionResource;
            }
        );

        $this->conditionPool = $this->createStub(ConditionPool::class);
        $this->conditionPool->method('get')->willReturnMap([
            ['tag', $this->createStub(ConditionInterface::class)],
        ]);

        $this->actionPool = $this->createStub(ActionPool::class);
        $this->actionPool->method('get')->willReturnMap([
            ['tag_customer', $this->createStub(ActionInterface::class)],
        ]);

        $this->importer = new CampaignImporter(
            $this->campaignFactory,
            $this->campaignResource,
            $this->campaignTriggerFactory,
            $campaignTriggerResource,
            $this->campaignConditionFactory,
            $campaignConditionResource,
            $this->campaignActionFactory,
            $campaignActionResource,
            $this->conditionPool,
            $this->actionPool,
            new TriggerEvent()
        );
    }

    public function testImportRejectsAWrongExportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['export_type' => 'ordo_segment', 'name' => 'x']);
    }

    public function testImportRejectsAMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['export_type' => 'ordo_campaign', 'name' => '']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportCreatesTheCampaignWithItsFields(): void
    {
        $campaign = $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'enabled' => true,
            'condition_logic' => 'any',
        ]);

        self::assertSame('Welcome Series', $campaign->getName());
        self::assertTrue($campaign->isEnabled());
        self::assertSame('any', $campaign->getConditionLogic());
        self::assertSame(9, $campaign->getEntityId());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportSavesAValidTrigger(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'triggers' => [
                ['trigger_event' => CampaignTriggerInterface::TRIGGER_ORDER_PLACED, 'params' => ['x' => 1]],
            ],
        ]);

        self::assertCount(1, $this->savedTriggers);
        self::assertSame(CampaignTriggerInterface::TRIGGER_ORDER_PLACED, $this->savedTriggers[0]->getTriggerEvent());
        self::assertSame(['x' => 1], $this->savedTriggers[0]->getParams());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAMalformedTriggerRow(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'triggers' => [
                'not-an-array',
                ['params' => []],
                ['trigger_event' => CampaignTriggerInterface::TRIGGER_ORDER_PLACED, 'params' => ['x' => 1]],
            ],
        ]);

        self::assertCount(1, $this->savedTriggers);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAnUnknownTriggerEvent(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'triggers' => [
                ['trigger_event' => 'this_event_does_not_exist', 'params' => []],
            ],
        ]);

        self::assertCount(0, $this->savedTriggers);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportSavesKnownConditionAndGroupTypes(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'conditions' => [
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
                ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]],
                ['type' => 'this_type_does_not_exist', 'params' => []],
            ],
        ]);

        self::assertCount(2, $this->savedConditions);
        self::assertSame('tag', $this->savedConditions[0]->getType());
        self::assertSame('group', $this->savedConditions[1]->getType());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAMalformedConditionRow(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'conditions' => [
                'not-an-array',
                ['params' => []],
                ['type' => ''],
                ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ],
        ]);

        self::assertCount(1, $this->savedConditions);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportSavesAValidActionWithDelayMinutes(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'actions' => [
                ['type' => 'tag_customer', 'params' => ['tag' => 'welcomed'], 'delay_minutes' => 60],
            ],
        ]);

        self::assertCount(1, $this->savedActions);
        self::assertSame('tag_customer', $this->savedActions[0]->getType());
        self::assertSame(['tag' => 'welcomed'], $this->savedActions[0]->getParams());
        self::assertSame(60, $this->savedActions[0]->getDelayMinutes());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDropsAnUnknownActionType(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'actions' => [
                ['type' => 'this_action_does_not_exist', 'params' => []],
            ],
        ]);

        self::assertCount(0, $this->savedActions);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportClampsANegativeDelayMinutesToZero(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'actions' => [
                ['type' => 'tag_customer', 'params' => [], 'delay_minutes' => -5],
            ],
        ]);

        self::assertSame(0, $this->savedActions[0]->getDelayMinutes());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImportDefaultsDelayMinutesToZeroForANonNumericValue(): void
    {
        $this->importer->import([
            'export_type' => 'ordo_campaign',
            'name' => 'Welcome Series',
            'actions' => [
                ['type' => 'tag_customer', 'params' => [], 'delay_minutes' => ['not-a-number']],
            ],
        ]);

        self::assertSame(0, $this->savedActions[0]->getDelayMinutes());
    }
}
