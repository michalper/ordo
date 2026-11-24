<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Segment;

use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;
use Ordo\Automation\Plugin\Shared\AbstractSaveProcessorAuditPlugin;

/**
 * Records who saved a segment, and what changed - see
 * Plugin\Shared\AbstractSaveProcessorAuditPlugin's own docblock for the identical reasoning
 * (this is Segment's counterpart, sharing the same Model\AdminActionLog\Recorder).
 */
class SegmentSaveProcessorAuditPlugin extends AbstractSaveProcessorAuditPlugin
{
    protected function entityType(): string
    {
        return 'segment';
    }

    protected function auditedFields(): array
    {
        return ['name', 'enabled', 'condition_logic'];
    }

    /**
     * @param callable(array<string, mixed>): Segment $proceed
     * @param array<string, mixed> $data
     */
    public function aroundProcess(SegmentSaveProcessor $subject, callable $proceed, array $data): Segment
    {
        /** @var Segment $segment */
        $segment = $this->doAroundProcess($proceed, $data);

        return $segment;
    }
}
