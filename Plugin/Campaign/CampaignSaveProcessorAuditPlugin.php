<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Campaign;

use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignSaveProcessor;
use Ordo\Automation\Plugin\Shared\AbstractSaveProcessorAuditPlugin;

/**
 * Records who saved a campaign, and what changed - see AbstractSaveProcessorAuditPlugin's own
 * docblock for the overall design. An `around` (not `after`) so create-vs-update can be
 * determined from the raw posted $data (an entity_id posted at all means "update") before
 * process() itself mutates anything - by the time process() returns, the campaign's own
 * getOrigData() no longer reflects "was this new" in a way this plugin needs to re-derive.
 */
class CampaignSaveProcessorAuditPlugin extends AbstractSaveProcessorAuditPlugin
{
    protected function entityType(): string
    {
        return 'campaign';
    }

    protected function auditedFields(): array
    {
        return ['name', 'enabled', 'condition_logic'];
    }

    /**
     * @param callable(array<string, mixed>): Campaign $proceed
     * @param array<string, mixed> $data
     */
    public function aroundProcess(CampaignSaveProcessor $subject, callable $proceed, array $data): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->doAroundProcess($proceed, $data);

        return $campaign;
    }
}
