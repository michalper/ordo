<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\ScoreRule;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;

/**
 * Records who saved a score rule, and what changed - see Plugin\ContentBlock\
 * ContentBlockResourceAuditPlugin's own docblock for the shared reasoning (resource-model-level
 * plugin instead of Campaign/Segment's SaveProcessor-level one, since this entity has no such
 * extracted processor).
 */
class ScoreRuleResourceAuditPlugin
{
    private const string ENTITY_TYPE = 'score_rule';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['attribute_code', 'operator', 'value', 'points', 'enabled'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(ScoreRule): AbstractDb $proceed
     */
    public function aroundSave(ScoreRuleResource $subject, callable $proceed, ScoreRule $model): AbstractDb
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
