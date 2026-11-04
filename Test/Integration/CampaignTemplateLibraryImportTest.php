<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Campaign\TemplateLibrary;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Proves each curated Model\Campaign\TemplateLibrary payload actually imports cleanly through
 * the real CampaignImporter - every trigger/condition/action row in the payload must land as a
 * real saved row, none silently dropped as malformed (CampaignImporter's own fail-soft behavior
 * for a row referencing an unregistered trigger/condition/action type - exactly the mistake a
 * typo'd template could make and never get caught by a unit test alone, which never touches the
 * real ConditionPool/ActionPool/TriggerEvent registrations).
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignTemplateLibraryImportTest.php
 */
class CampaignTemplateLibraryImportTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private array $campaignIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaignFactory = self::$objectManager->get(\Ordo\Automation\Model\CampaignFactory::class);

        foreach ($this->campaignIds as $campaignId) {
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getEntityId()) {
                $campaignResource->delete($campaign);
            }
        }
        $this->campaignIds = [];
    }

    public function testEveryCuratedTemplateImportsWithEveryTriggerConditionAndActionRowSaved(): void
    {
        $templateLibrary = self::$objectManager->get(TemplateLibrary::class);
        $campaignImporter = self::$objectManager->get(CampaignImporter::class);
        $triggerCollectionFactory = self::$objectManager->get(TriggerCollectionFactory::class);
        $conditionCollectionFactory = self::$objectManager->get(ConditionCollectionFactory::class);
        $actionCollectionFactory = self::$objectManager->get(ActionCollectionFactory::class);

        foreach ($templateLibrary->getTemplates() as $template) {
            $payload = $template['payload'];

            $campaign = $campaignImporter->import($payload);
            $campaignId = (int) $campaign->getEntityId();
            $this->campaignIds[] = $campaignId;

            self::assertGreaterThan(0, $campaignId, $template['id'] . ': campaign must have saved');
            self::assertFalse((bool) $campaign->getEnabled(), $template['id'] . ': must import disabled');

            $triggerCount = $triggerCollectionFactory->create()->addCampaignFilter($campaignId)->getSize();
            $conditionCount = $conditionCollectionFactory->create()->addCampaignFilter($campaignId)->getSize();
            $actionCount = $actionCollectionFactory->create()->addCampaignFilter($campaignId)->getSize();

            self::assertSame(
                count($payload['triggers']),
                $triggerCount,
                $template['id'] . ': every declared trigger row must have been saved, none dropped as unrecognized'
            );
            self::assertSame(
                count($payload['conditions']),
                $conditionCount,
                $template['id'] . ': every declared condition row must have been saved, none dropped as unrecognized'
            );
            self::assertSame(
                count($payload['actions']),
                $actionCount,
                $template['id'] . ': every declared action row must have been saved, none dropped as unrecognized'
            );
        }
    }
}
