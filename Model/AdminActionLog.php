<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\AdminActionLog as AdminActionLogResource;

/**
 * One row per admin Campaign/Segment save - see etc/db_schema.xml's ordo_admin_action_log
 * comment and Plugin\Campaign\CampaignSaveProcessorAuditPlugin /
 * Plugin\Segment\SegmentSaveProcessorAuditPlugin, the only things that write these. Plain data
 * holder, insert-only.
 */
class AdminActionLog extends AbstractModel
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_UPDATE = 'update';

    protected function _construct(): void
    {
        $this->_init(AdminActionLogResource::class);
    }

    public function setEntityType(string $entityType): self
    {
        $this->setData('entity_type', $entityType);
        return $this;
    }

    public function setTargetEntityId(?int $targetEntityId): self
    {
        $this->setData('target_entity_id', $targetEntityId);
        return $this;
    }

    public function setAction(string $action): self
    {
        $this->setData('action', $action);
        return $this;
    }

    public function setAdminUserId(?int $adminUserId): self
    {
        $this->setData('admin_user_id', $adminUserId);
        return $this;
    }

    public function setAdminUsername(?string $adminUsername): self
    {
        $this->setData('admin_username', $adminUsername);
        return $this;
    }

    public function setChangesJson(?string $changesJson): self
    {
        $this->setData('changes_json', $changesJson);
        return $this;
    }
}
