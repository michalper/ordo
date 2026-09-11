<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\WhatsAppTemplate;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;

/**
 * Records who saved a WhatsApp template, and what changed - see Plugin\ContentBlock\
 * ContentBlockResourceAuditPlugin's own docblock for the shared reasoning. Also covers
 * Controller\Adminhtml\WhatsAppTemplate\{SubmitForReview,RefreshStatus} for free, since both
 * save through this same resource and are themselves real admin actions worth auditing (status
 * moving to "pending", or a poll picking up Meta's approve/reject decision) - not just Save.
 * `status` is included in the audited fields for exactly that reason, even though the admin
 * form itself never posts it directly.
 */
class WhatsAppTemplateResourceAuditPlugin
{
    private const string ENTITY_TYPE = 'whatsapp_template';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'meta_template_name', 'category', 'language', 'body_text', 'status'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(WhatsAppTemplate): AbstractDb $proceed
     */
    public function aroundSave(
        WhatsAppTemplateResource $subject,
        callable $proceed,
        WhatsAppTemplate $model
    ): AbstractDb {
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
