<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Campaign;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignSaveProcessor;

/**
 * Records who saved a campaign, and what changed - see Model\AdminActionLog\Recorder's own
 * docblock for the overall design. An `around` (not `after`) so create-vs-update can be
 * determined from the raw posted $data (an entity_id posted at all means "update") before
 * process() itself mutates anything - by the time process() returns, the campaign's own
 * getOrigData() no longer reflects "was this new" in a way this plugin needs to re-derive.
 */
class CampaignSaveProcessorAuditPlugin
{
    private const string ENTITY_TYPE = 'campaign';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'enabled', 'condition_logic'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(array<string, mixed>): Campaign $proceed
     * @param array<string, mixed> $data
     */
    public function aroundProcess(CampaignSaveProcessor $subject, callable $proceed, array $data): Campaign
    {
        $isCreate = empty($data['entity_id']);

        $campaign = $proceed($data);

        $this->recorder->record(
            self::ENTITY_TYPE,
            $campaign->getEntityId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($campaign, self::AUDITED_FIELDS)
        );

        return $campaign;
    }
}
