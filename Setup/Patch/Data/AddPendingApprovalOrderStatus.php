<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * Registers a custom order status ("Pending Approval") within the existing "new" state,
 * instead of inventing a whole new state — keeps the order in a state Magento already
 * understands (holds inventory reservation, doesn't trigger invoicing) while being visible
 * and filterable in the admin grid as its own status.
 */
class AddPendingApprovalOrderStatus implements DataPatchInterface
{
    public const STATUS_PENDING_APPROVAL = 'ordo_pending_approval';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $connection = $this->moduleDataSetup->getConnection();

        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status'),
            [
                'status' => self::STATUS_PENDING_APPROVAL,
                'label' => 'Pending Approval',
            ],
            ['label']
        );

        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            [
                'status' => self::STATUS_PENDING_APPROVAL,
                'state' => Order::STATE_NEW,
                'is_default' => 0,
            ],
            ['state']
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
