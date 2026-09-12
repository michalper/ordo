<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ConversationMessage\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Psr\Log\LoggerInterface;
use Zend_Db_Expr;

/**
 * Same SearchResult-based grid collection shape as Model\ResourceModel\MessageLog\Grid\
 * Collection, including the same customer_id -> name/email LEFT JOIN, so the conversation view
 * reads like a real customer-tied conversation instead of a raw phone-number dump.
 */
class Collection extends SearchResult
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly ResourceConnection $resourceConnection,
        $mainTable = 'ordo_conversation_message',
        $resourceModel = ConversationMessageResource::class,
        $identifierName = 'entity_id',
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect(): void
    {
        parent::_initSelect();

        $this->getSelect()->joinLeft(
            ['customer' => $this->resourceConnection->getTableName('customer_entity')],
            'customer.entity_id = main_table.customer_id',
            [
                'customer_name' => new Zend_Db_Expr("CONCAT(customer.firstname, ' ', customer.lastname)"),
                'customer_email' => 'customer.email',
            ]
        );
    }
}
