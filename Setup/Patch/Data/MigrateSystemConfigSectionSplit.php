<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * `etc/adminhtml/system.xml` used to have every group under one flat "General" section
 * (`ordo_automation`), a single left-nav entry the user's own admin no longer scrolled through
 * comfortably once the module grew past 30 groups. It's now split into six sections
 * (`ordo_automation` itself, kept for the groups that stay put, plus `ordo_b2b`, `ordo_scoring`,
 * `ordo_channels`, `ordo_tracking`, `ordo_integrations`), each still under the same "Ordo
 * Automation" tab.
 *
 * A stored config value's `core_config_data.path` is the literal string `section/group/field`,
 * so any group that moved to a new section id needs its already-stored rows rewritten in place —
 * otherwise an existing install would silently fall back to that field's XML default the moment
 * this release lands, discarding whatever the merchant had configured (API keys, thresholds,
 * delay minutes, etc.) without any error.
 */
class MigrateSystemConfigSectionSplit implements DataPatchInterface
{
    /** @var array<string, string> group id => new section id, for every group that moved out of ordo_automation */
    private const array GROUP_TO_SECTION = [
        'credit_limit' => 'ordo_b2b',
        'order_approval' => 'ordo_b2b',
        'sales_rep' => 'ordo_b2b',
        'lead_routing' => 'ordo_b2b',
        'lead_scoring' => 'ordo_scoring',
        'clv' => 'ordo_scoring',
        'attribution' => 'ordo_scoring',
        'ab_test' => 'ordo_scoring',
        'email' => 'ordo_channels',
        'email_template_versioning' => 'ordo_channels',
        'sms' => 'ordo_channels',
        'whatsapp' => 'ordo_channels',
        'push' => 'ordo_channels',
        'webhook' => 'ordo_channels',
        'quiet_hours' => 'ordo_channels',
        'frequency_cap' => 'ordo_channels',
        'tracking' => 'ordo_tracking',
        'ai' => 'ordo_tracking',
        'ad_audience_sync' => 'ordo_integrations',
        'shopping_feed' => 'ordo_integrations',
        'meta_catalog_feed' => 'ordo_integrations',
        'ai_agent' => 'ordo_integrations',
    ];

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

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        if (!$connection->isTableExists($table)) {
            return $this;
        }

        foreach (self::GROUP_TO_SECTION as $group => $newSection) {
            $oldPrefix = 'ordo_automation/' . $group . '/';
            $newPrefix = $newSection . '/' . $group . '/';

            // $newPrefix is one of this class's own constants, never user input, so it's safe
            // to inline directly rather than round-trip it through quote()'s mixed return type.
            $connection->update(
                $table,
                ['path' => new \Zend_Db_Expr(
                    "CONCAT('" . $newPrefix . "', SUBSTRING(path, "
                    . (strlen($oldPrefix) + 1) . '))'
                )],
                ['path LIKE ?' => $oldPrefix . '%']
            );
        }

        return $this;
    }
}
