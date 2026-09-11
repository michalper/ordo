<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdminActionLog;

use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Ordo\Automation\Model\AdminActionLogFactory;
use Ordo\Automation\Model\ResourceModel\AdminActionLog as AdminActionLogResource;
use Psr\Log\LoggerInterface;

/**
 * Shared write path for the admin action audit log (ordo_admin_action_log) - used by
 * Plugin\Campaign\CampaignSaveProcessorAuditPlugin and Plugin\Segment\SegmentSaveProcessorAuditPlugin,
 * kept as one class rather than duplicating the admin-session/persistence logic in each plugin.
 *
 * Scoped to Campaign/Segment saves only for now (ROADMAP.md "admin action audit log" - narrowed
 * to the two highest-value entities first, same "start narrow, extend mechanically later"
 * pattern as the mass-action work before it); every other admin-managed entity's save is not yet
 * audited.
 */
class Recorder
{
    public function __construct(
        private readonly BackendAuthSession $authSession,
        private readonly AdminActionLogFactory $adminActionLogFactory,
        private readonly AdminActionLogResource $adminActionLogResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}>|null $changes {field: [old, new]}, null on
     *   create (there's no "old" to diff against)
     */
    public function record(
        string $entityType,
        ?int $targetEntityId,
        string $action,
        ?array $changes
    ): void {
        try {
            $user = $this->authSession->getUser();

            $entry = $this->adminActionLogFactory->create();
            $entry->setEntityType($entityType);
            $entry->setTargetEntityId($targetEntityId);
            $entry->setAction($action);
            $entry->setAdminUserId($user !== null ? (int) $user->getUserId() : null);
            $entry->setAdminUsername($user !== null ? (string) $user->getUserName() : null);
            $entry->setChangesJson($changes !== null && $changes !== [] ? (string) json_encode($changes) : null);
            $this->adminActionLogResource->save($entry);
        } catch (\Throwable $e) {
            // A DB hiccup persisting the audit row must never block or fail the actual save it's
            // auditing - same "best-effort, swallow and log" reasoning as CronRunLogger::persist().
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to persist admin action log entry: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Diffs a fixed, known set of top-level scalar fields between an entity's pre-load state
     * (getOrigData(), populated by ResourceModel::load() before the save processor's setters ran
     * - empty on a brand-new entity) and its current state. Deliberately doesn't diff child rows
     * (triggers/conditions/actions) - those are always fully deleted and re-inserted by the save
     * processors regardless of whether they actually changed, so a diff there would be all noise.
     *
     * @param string[] $fields
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function diffFields(\Magento\Framework\Model\AbstractModel $entity, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $entity->getOrigData($field);
            $new = $entity->getData($field);
            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }
}
