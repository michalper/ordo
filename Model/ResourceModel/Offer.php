<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Api\Data\OfferInterface;

class Offer extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_offer', 'entity_id');
    }

    /**
     * Atomically applies a self-extension - a plain load()-then-save() lets two concurrent
     * self-extend requests for the same offer both read the same pre-increment extension_count,
     * both pass the "under the max" check, and race each other to write: the loser's expiry
     * update is silently overwritten by whichever request commits last (a lost update), yet
     * both still land on the same final extension_count, quietly understating how many times the
     * offer was actually extended. This does the update as a single conditional
     * UPDATE ... WHERE extension_count = :expected, so only whichever request's UPDATE still
     * matches the row it read may proceed; the loser sees 0 affected rows and the caller decides
     * whether that means "someone else already extended it" or "genuinely at the max".
     */
    public function claimSelfExtension(
        OfferInterface $model,
        string $newExpiresAt,
        int $expectedExtensionCount
    ): bool {
        $connection = $this->getConnection();
        $newExtensionCount = $expectedExtensionCount + 1;

        $affectedRows = $connection->update(
            $this->getMainTable(),
            ['expires_at' => $newExpiresAt, 'extension_count' => $newExtensionCount],
            $connection->quoteInto('entity_id = ?', (int) $model->getEntityId())
            . ' AND extension_count = ' . $connection->quoteInto('?', $expectedExtensionCount)
        );

        if ($affectedRows > 0) {
            $model->setExpiresAt($newExpiresAt);
            $model->setExtensionCount($newExtensionCount);
        }

        return $affectedRows > 0;
    }

    /**
     * Marks every "sent" offer whose expires_at has passed as "expired" - Cron\ExpireOverdueOffers
     * used to load()/save() each one individually (one UPDATE per offer, every run); a single
     * batch UPDATE does the same thing in one round trip regardless of how many offers are due,
     * same shape as every other "mark these rows done" claim in this module.
     *
     * @return int how many offers were actually marked expired, for the cron's own run summary.
     */
    public function expireOverdue(string $today): int
    {
        $connection = $this->getConnection();

        return (int) $connection->update(
            $this->getMainTable(),
            ['status' => OfferInterface::STATUS_EXPIRED],
            [
                'status = ?' => OfferInterface::STATUS_SENT,
                'expires_at < ?' => $today,
            ]
        );
    }
}
