<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\AdAudience;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;

/**
 * Records who saved an ad audience, and what changed - see Plugin\ContentBlock\
 * ContentBlockResourceAuditPlugin's own docblock for the shared reasoning. The
 * Recorder::hasLoggedInAdmin() guard matters concretely here, unlike some of its siblings:
 * Cron\SyncAdAudiences also calls AdAudienceResource::save() directly (updating
 * last_sync_status/last_synced_at on its own periodic schedule) - without the guard, every sync
 * tick would show up in the admin audit log misattributed as an admin action. Only name/
 * segment_id/platform/enabled are audited - the sync-status fields a background job legitimately
 * updates are deliberately excluded even for real admin saves, since they're never posted by the
 * admin form itself.
 */
class AdAudienceResourceAuditPlugin
{
    private const string ENTITY_TYPE = 'ad_audience';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'segment_id', 'platform', 'enabled'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(AdAudience): AbstractDb $proceed
     */
    public function aroundSave(AdAudienceResource $subject, callable $proceed, AdAudience $model): AbstractDb
    {
        if (!$this->recorder->hasLoggedInAdmin()) {
            return $proceed($model);
        }

        $isCreate = !$model->getId();
        $result = $proceed($model);

        $this->recorder->record(
            self::ENTITY_TYPE,
            (int) $model->getId(),
            $isCreate ? AdminActionLog::ACTION_CREATE : AdminActionLog::ACTION_UPDATE,
            $isCreate ? null : $this->recorder->diffFields($model, self::AUDITED_FIELDS)
        );

        return $result;
    }
}
