<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OrderApproval extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_order_approval', 'entity_id');
    }

    public function loadByToken(\Ordo\Automation\Model\OrderApproval $model, string $token): void
    {
        $connection = $this->getConnection();
        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), 'entity_id')
                ->where('token = ?', $token)
        );

        if ($entityId) {
            $this->load($model, (int) $entityId);
        }
    }
}
