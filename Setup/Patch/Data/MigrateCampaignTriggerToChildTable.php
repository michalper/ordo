<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * A campaign's trigger moved from a single `ordo_campaign.trigger_event` column to its own
 * child table, `ordo_campaign_trigger` (CampaignTriggerInterface) — a campaign can now fire on
 * more than one trigger event. This patch copies every existing campaign's trigger_event into
 * one row in the new table before any code stops reading the old column.
 *
 * The old column itself is deliberately NOT dropped here — declarative schema applies the full
 * db_schema.xml diff before any data patch runs, so removing the column in the same release
 * would drop it before this patch ever gets a chance to read it. It's kept, nullable and
 * unread by any other code path, purely so this patch has something to migrate from; safe to
 * remove in a later schema-only release once every install has run this patch.
 */
class MigrateCampaignTriggerToChildTable implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $campaignTable = $this->moduleDataSetup->getTable('ordo_campaign');
        $triggerTable = $this->moduleDataSetup->getTable('ordo_campaign_trigger');

        if (!$connection->isTableExists($campaignTable) || !$connection->tableColumnExists($campaignTable, 'trigger_event')) {
            return;
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($campaignTable, ['entity_id', 'trigger_event'])
                ->where('trigger_event IS NOT NULL')
                ->where('trigger_event != ?', '')
        );

        foreach ($rows as $row) {
            $exists = (bool) $connection->fetchOne(
                $connection->select()
                    ->from($triggerTable, 'entity_id')
                    ->where('campaign_id = ?', $row['entity_id'])
                    ->where('trigger_event = ?', $row['trigger_event'])
            );

            if ($exists) {
                continue;
            }

            $connection->insert($triggerTable, [
                'campaign_id' => $row['entity_id'],
                'trigger_event' => $row['trigger_event'],
            ]);
        }
    }
}
