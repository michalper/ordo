<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\FreeGiftOffer;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

/**
 * Records who saved a free gift offer, and what changed - see Plugin\ContentBlock\
 * ContentBlockResourceAuditPlugin's own docblock for the shared reasoning (resource-model-level
 * plugin instead of Campaign/Segment's SaveProcessor-level one, since this entity has no such
 * extracted processor).
 */
class FreeGiftOfferResourceAuditPlugin
{
    private const string ENTITY_TYPE = 'free_gift_offer';

    /**
     * @var string[]
     */
    private const array AUDITED_FIELDS = ['name', 'enabled'];

    public function __construct(
        private readonly Recorder $recorder
    ) {
    }

    /**
     * @param callable(FreeGiftOffer): AbstractDb $proceed
     */
    public function aroundSave(FreeGiftOfferResource $subject, callable $proceed, FreeGiftOffer $model): AbstractDb
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
