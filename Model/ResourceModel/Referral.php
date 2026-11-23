<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\Referral as ReferralModel;

class Referral extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_referral', 'entity_id');
    }

    /**
     * Atomically transitions a pending referral to converted - a plain load()-then-save() lets two
     * overlapping calls for the same referred customer (a retried order-placement event, or an
     * admin edit racing a webhook resave) both pass the "still pending" check before either writes,
     * rewarding the referrer twice for one conversion. This does the transition as a single
     * conditional UPDATE ... WHERE status = 'pending', so only whichever call's UPDATE actually
     * matches a row may proceed; the loser sees 0 affected rows - same pattern
     * Model\ResourceModel\OrderApproval::claimPending() already uses for its own equivalent race.
     */
    public function claimConverted(ReferralModel $model): bool
    {
        $connection = $this->getConnection();
        $convertedAt = date('Y-m-d H:i:s');

        $affectedRows = $connection->update(
            $this->getMainTable(),
            [ReferralModel::STATUS => ReferralModel::STATUS_CONVERTED, ReferralModel::CONVERTED_AT => $convertedAt],
            $connection->quoteInto('entity_id = ?', (int) $model->getEntityId())
            . ' AND status = ' . $connection->quote(ReferralModel::STATUS_PENDING)
        );

        if ($affectedRows > 0) {
            $model->setData(ReferralModel::STATUS, ReferralModel::STATUS_CONVERTED);
            $model->setData(ReferralModel::CONVERTED_AT, $convertedAt);
        }

        return $affectedRows > 0;
    }
}
