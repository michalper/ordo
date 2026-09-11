<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\ContentBlock;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * Records who saved a content block, and what changed - closes the admin-platform ROADMAP.md gap
 * where the audit log covered only Campaign/Segment saves. Unlike those two (audited via a
 * dedicated SaveProcessor's own process() method, only ever invoked from the admin Save
 * controller), ContentBlock has no such extracted processor - Controller\Adminhtml\ContentBlock\
 * Save builds and saves the model inline. Plugging ResourceModel::save() itself instead reaches
 * the same admin Save action without a controller-level plugin needing to re-derive the saved
 * entity's id from a redirect it can't see into - but that resource is also called from
 * non-admin-action code as a plain model persistence step in this codebase's other entities'
 * cases; ContentBlock itself has none such today, but the same Recorder::hasLoggedInAdmin()
 * guard is applied here anyway for consistency with its siblings (AdAudience, WhatsAppTemplate)
 * that do.
 */
class ContentBlockResourceAuditPlugin
{
    private const string ENTITY_TYPE = 'content_block';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'identifier', 'type', 'enabled'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(ContentBlock): AbstractDb $proceed
     */
    public function aroundSave(ContentBlockResource $subject, callable $proceed, ContentBlock $model): AbstractDb
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
