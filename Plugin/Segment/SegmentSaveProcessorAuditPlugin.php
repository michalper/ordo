<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Segment;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;

/**
 * Records who saved a segment, and what changed - see
 * Plugin\Campaign\CampaignSaveProcessorAuditPlugin's own docblock for the identical reasoning
 * (this is Segment's counterpart, sharing the same Model\AdminActionLog\Recorder).
 */
class SegmentSaveProcessorAuditPlugin
{
    private const string ENTITY_TYPE = 'segment';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'enabled', 'condition_logic'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(array<string, mixed>): Segment $proceed
     * @param array<string, mixed> $data
     */
    public function aroundProcess(SegmentSaveProcessor $subject, callable $proceed, array $data): Segment
    {
        $isCreate = empty($data['entity_id']);

        $segment = $proceed($data);

        $this->recorder->record(
            self::ENTITY_TYPE,
            $segment->getEntityId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($segment, self::AUDITED_FIELDS)
        );

        return $segment;
    }
}
