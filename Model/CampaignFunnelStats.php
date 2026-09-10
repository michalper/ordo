<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Composes one campaign's funnel (sent → delivered → opened → clicked → converted), one row per
 * split-test variant (empty-string variant key '' for a non-split-tested send) — the single
 * class Block\Adminhtml\Campaign\FunnelViewModel reads from.
 *
 * Deliberately two separate, focused queries rather than one giant join, same "additive to each
 * log's own narrow purpose" philosophy as Model\CampaignOutcomeLogger's own docblock: sent/
 * delivered/opened/clicked come from ordo_message_log(+_event), converted/revenue come from
 * CampaignOutcomeLogger (ordo_campaign_outcome_log) — the two tables serve different questions
 * ("did we send it" vs. "did it convert") and are only stitched together here, at read time.
 */
class CampaignFunnelStats
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger
    ) {
    }

    /**
     * @return array<int, array{
     *     variant: string|null,
     *     sent: int,
     *     delivered: int,
     *     opened: int,
     *     clicked: int,
     *     converted: int,
     *     conversion_rate: float,
     *     revenue: float
     * }>
     */
    public function getForCampaign(int $campaignId): array
    {
        $sendStats = $this->getSendStats($campaignId);
        $outcomeStats = [];
        foreach ($this->campaignOutcomeLogger->getStats($campaignId) as $row) {
            $outcomeStats[$row['variant'] ?? ''] = $row;
        }

        // Union of variant keys from both sources - a variant can appear in one without the
        // other (e.g. a send just happened with no conversion yet, or vice versa in the
        // vanishingly rare case a row was manually attributed without a matching send row).
        $variants = array_unique(array_merge(array_keys($sendStats), array_keys($outcomeStats)));

        $rows = [];
        foreach ($variants as $variant) {
            $send = $sendStats[$variant] ?? ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0];
            $outcome = $outcomeStats[$variant] ?? ['converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0];

            $rows[] = [
                'variant' => $variant === '' ? null : $variant,
                'sent' => $send['sent'],
                'delivered' => $send['delivered'],
                'opened' => $send['opened'],
                'clicked' => $send['clicked'],
                'converted' => $outcome['converted'],
                'conversion_rate' => $outcome['conversion_rate'],
                'revenue' => $outcome['revenue'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{sent: int, delivered: int, opened: int, clicked: int}>
     */
    private function getSendStats(int $campaignId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $logTable = $this->resourceConnection->getTableName('ordo_message_log');
        $eventTable = $this->resourceConnection->getTableName('ordo_message_log_event');

        /**
         * @var array<int, array{
         *     variant: string|null,
         *     sent: string|int,
         *     delivered: string|int,
         *     opened: string|int,
         *     clicked: string|int
         * }> $rows
         */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['l' => $logTable], [
                    'variant',
                    'sent' => 'COUNT(*)',
                    'delivered' => 'SUM(CASE WHEN l.status = \'delivered\' THEN 1 ELSE 0 END)',
                ])
                ->where('l.campaign_id = ?', $campaignId)
                ->group('variant')
        );

        /** @var array<string, array{sent: int, delivered: int, opened: int, clicked: int}> $stats */
        $stats = [];
        foreach ($rows as $row) {
            $variant = $row['variant'] ?? '';
            $stats[$variant] = [
                'sent' => (int) $row['sent'],
                'delivered' => (int) $row['delivered'],
                'opened' => 0,
                'clicked' => 0,
            ];
        }

        // COUNT(DISTINCT message_log_id) - a message opened/clicked more than once still only
        // counts once toward the funnel, same "each stage counted once" semantics the funnel
        // view needs (see ordo_message_log_event's own db_schema.xml comment for why raw event
        // rows aren't 1:1 with a funnel count).
        /**
         * @var array<int, array{variant: string|null, event_type: string, count: string|int}> $eventRows
         */
        $eventRows = $connection->fetchAll(
            $connection->select()
                ->from(['e' => $eventTable], ['event_type'])
                ->joinInner(['l' => $logTable], 'l.entity_id = e.message_log_id', ['variant'])
                ->where('l.campaign_id = ?', $campaignId)
                ->columns(['count' => 'COUNT(DISTINCT e.message_log_id)'])
                ->group(['variant', 'event_type'])
        );

        foreach ($eventRows as $row) {
            $variant = $row['variant'] ?? '';
            if (!isset($stats[$variant])) {
                continue;
            }
            if ($row['event_type'] === MessageLogEvent::TYPE_OPENED) {
                $stats[$variant]['opened'] = (int) $row['count'];
            } elseif ($row['event_type'] === MessageLogEvent::TYPE_CLICKED) {
                $stats[$variant]['clicked'] = (int) $row['count'];
            }
        }

        return $stats;
    }
}
