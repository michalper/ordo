<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Customer360;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\MessageLog;

/**
 * One customer's own chronological sent/opened/clicked/read/converted history - merged from three
 * tables no existing report in this module reads per-customer, in order: ordo_message_log
 * ("sent"), ordo_message_log_event ("opened"/"clicked"/"read"), and ordo_campaign_outcome_log
 * ("converted", only rows with acted_at set). Every other report here reads these aggregated
 * across customers (CampaignOutcomeLogger::getStats()) or scoped to one campaign
 * (Model\Campaign\Condition\MessageEngaged's own query) - never as one customer's own timeline,
 * which is the specific gap this class closes. Pure read-only SQL over existing tables, same
 * "no new table, no cron" shape every other analytics addition in this module already uses.
 */
class CustomerTouchpointReader
{
    /**
     * Each of the three queries below is independently capped at this limit (not the merged
     * result) - a customer with thousands of old sends but no recent activity would otherwise
     * have their "sent" rows alone crowd out every "converted"/"opened" row once the final
     * merge-and-slice below runs.
     */
    private const int PER_TYPE_LIMIT = 50;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @return CustomerTouchpoint[] Most recent first.
     */
    public function getTimeline(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $rows = [
            ...$connection->fetchAll($this->sentSelect($customerId)),
            ...$connection->fetchAll($this->eventSelect($customerId)),
            ...$connection->fetchAll($this->convertedSelect($customerId)),
        ];

        $touchpoints = array_map(
            static fn (array $row): CustomerTouchpoint => new CustomerTouchpoint(
                type: $row['type'],
                occurredAt: $row['occurred_at'],
                channel: $row['channel'],
                campaignName: $row['campaign_name'],
                orderId: $row['order_id'] !== null ? (int) $row['order_id'] : null
            ),
            $rows
        );

        usort(
            $touchpoints,
            static fn (CustomerTouchpoint $left, CustomerTouchpoint $right): int => strcmp(
                $right->occurredAt,
                $left->occurredAt
            )
        );

        return array_slice($touchpoints, 0, self::PER_TYPE_LIMIT);
    }

    private function sentSelect(int $customerId): Select
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->select()
            ->from(['ml' => $this->resourceConnection->getTableName('ordo_message_log')], [
                'type' => new \Zend_Db_Expr("'sent'"),
                'occurred_at' => 'ml.sent_at',
                'channel' => 'ml.channel',
                'order_id' => new \Zend_Db_Expr('NULL'),
            ])
            ->joinLeft(
                ['c' => $this->resourceConnection->getTableName('ordo_campaign')],
                'c.entity_id = ml.campaign_id',
                ['campaign_name' => 'c.name']
            )
            ->where('ml.customer_id = ?', $customerId)
            // Same exclusion Model\ResourceModel\MessageLog\Collection::addRealSendAttemptFilter()
            // already applies for FrequencyCapManager - an opted-out/suppressed row never actually
            // reached the customer, so it isn't a real "sent" touchpoint to show in their history.
            ->where('ml.status NOT IN (?)', [MessageLog::STATUS_OPTED_OUT, MessageLog::STATUS_SUPPRESSED])
            ->order('ml.sent_at DESC')
            ->limit(self::PER_TYPE_LIMIT);
    }

    private function eventSelect(int $customerId): Select
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->select()
            ->from(
                ['mle' => $this->resourceConnection->getTableName('ordo_message_log_event')],
                ['type' => 'mle.event_type', 'occurred_at' => 'mle.created_at']
            )
            ->joinInner(
                ['ml' => $this->resourceConnection->getTableName('ordo_message_log')],
                'ml.entity_id = mle.message_log_id',
                ['channel' => 'ml.channel']
            )
            ->joinLeft(
                ['c' => $this->resourceConnection->getTableName('ordo_campaign')],
                'c.entity_id = ml.campaign_id',
                ['campaign_name' => 'c.name']
            )
            ->columns(['order_id' => new \Zend_Db_Expr('NULL')])
            ->where('ml.customer_id = ?', $customerId)
            ->order('mle.created_at DESC')
            ->limit(self::PER_TYPE_LIMIT);
    }

    private function convertedSelect(int $customerId): Select
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->select()
            ->from(
                ['o' => $this->resourceConnection->getTableName('ordo_campaign_outcome_log')],
                [
                    'type' => new \Zend_Db_Expr("'converted'"),
                    'occurred_at' => 'o.acted_at',
                    'channel' => new \Zend_Db_Expr('NULL'),
                    'order_id' => 'o.order_id',
                ]
            )
            ->joinLeft(
                ['c' => $this->resourceConnection->getTableName('ordo_campaign')],
                'c.entity_id = o.campaign_id',
                ['campaign_name' => 'c.name']
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('o.acted_at IS NOT NULL')
            ->order('o.acted_at DESC')
            ->limit(self::PER_TYPE_LIMIT);
    }
}
