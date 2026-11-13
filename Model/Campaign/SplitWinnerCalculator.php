<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\Config\Source\AbTestDecisionMetric;
use Ordo\Automation\Model\MessageLogEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;

/**
 * Auto-picks a winner for every real 'split' campaign action once each of its variants has
 * enough real sends to compare CTR meaningfully — closes ROADMAP.md's "auto-pick a winner for
 * A/B-split campaign variants" candidate. The manual, weighted split itself
 * (Model\Campaign\SplitVariantSelector, the 'split' action) already exists and keeps running
 * variants at their admin-configured weights forever on its own; this is what actually looks at
 * the resulting ordo_message_log/ordo_message_log_event data and, once a variant has clearly
 * pulled ahead on click-through rate, shifts all future traffic to it — the same "declare a
 * winner and stop splitting" behavior vendors like Mailchimp/Optimizely ship as an opt-in
 * feature, not a continuous multi-armed-bandit reallocation.
 *
 * A decision is permanent and one-shot per split action: once made, the action's own params gain
 * a `winner`/`winner_decided_at` marker and every future pass skips it - re-editing the split's
 * variants in the admin (which rewrites params.variants entirely, see CampaignSaveProcessor)
 * clears that marker along with the old weights, so a genuinely new test naturally gets a fresh
 * decision instead of the old one lingering.
 *
 * The comparison metric is configurable (Config::getAbTestDecisionMetric() - CTR, conversion
 * rate, or revenue; see AbTestDecisionMetric), not hardcoded to CTR. Conversion rate/revenue are
 * read from the existing Model\CampaignOutcomeLogger::getStats() data (already computed
 * sent/converted/revenue per variant for the campaign funnel report) - not a new ledger. CTR
 * keeps reading ordo_message_log/ordo_message_log_event directly, the original data source,
 * since CampaignOutcomeLogger has no notion of opens/clicks.
 */
class SplitWinnerCalculator
{
    private const string TYPE_SPLIT = 'split';
    private const int WINNER_WEIGHT = 100;
    private const int LOSER_WEIGHT = 0;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CampaignActionResource $campaignActionResource,
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger
    ) {
    }

    /**
     * @return int how many split actions were just decided by this pass
     */
    public function decideWinners(): int
    {
        if (!$this->config->isAbTestAutoWinnerEnabled()) {
            return 0;
        }

        $minSampleSize = $this->config->getAbTestMinSampleSize();
        $decided = 0;

        $actions = $this->campaignActionCollectionFactory->create()->addTypeFilter(self::TYPE_SPLIT);

        /** @var CampaignAction $actionRow */
        foreach ($actions as $actionRow) {
            if ($this->decideOne($actionRow, $minSampleSize)) {
                $decided++;
            }
        }

        return $decided;
    }

    private function decideOne(CampaignAction $actionRow, int $minSampleSize): bool
    {
        $params = $actionRow->getParams();
        if (isset($params['winner'])) {
            // Already decided by an earlier pass - permanent, see this class's own docblock.
            return false;
        }

        $variants = $params['variants'] ?? null;
        if (!is_array($variants) || count($variants) < 2) {
            // Nothing to compare - either malformed or a single-variant split.
            return false;
        }

        $keys = [];
        foreach ($variants as $variant) {
            if (is_array($variant) && isset($variant['key']) && is_string($variant['key']) && $variant['key'] !== '') {
                $keys[] = $variant['key'];
            }
        }
        if (count($keys) < 2) {
            return false;
        }

        $metric = $this->config->getAbTestDecisionMetric();
        $campaignId = $actionRow->getCampaignId();
        $stats = $metric === AbTestDecisionMetric::CTR
            ? $this->fetchStats($campaignId, $keys)
            : $this->fetchOutcomeStats($campaignId, $keys);

        // $keys is never empty here (the count($keys) < 2 guard above already returned), and
        // every metric's own score is always >= 0.0 > this loop's own initial -1.0, so its first
        // iteration always sets $bestKey - there is no code path where it stays unset.
        $bestKey = $keys[0];
        $bestScore = -1.0;
        foreach ($keys as $key) {
            $sent = $stats[$key]['sent'] ?? 0;
            if ($sent < $minSampleSize) {
                // At least one variant still hasn't reached the minimum sample size - not ready
                // to decide yet, re-checked again on the next pass.
                return false;
            }

            $score = match ($metric) {
                AbTestDecisionMetric::CONVERSION_RATE => $stats[$key]['conversion_rate'] ?? 0.0,
                AbTestDecisionMetric::REVENUE => $stats[$key]['revenue'] ?? 0.0,
                default => $sent > 0 ? ($stats[$key]['clicked'] ?? 0) / $sent : 0.0,
            };
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        foreach ($variants as &$variant) {
            if (is_array($variant) && ($variant['key'] ?? null) === $bestKey) {
                $variant['weight'] = self::WINNER_WEIGHT;
            } elseif (is_array($variant)) {
                $variant['weight'] = self::LOSER_WEIGHT;
            }
        }
        unset($variant);

        $params['variants'] = $variants;
        $params['winner'] = $bestKey;
        $params['winner_decided_at'] = date('Y-m-d H:i:s');

        $actionRow->setParamsJson((string) json_encode($params));
        $this->campaignActionResource->save($actionRow);

        return true;
    }

    /**
     * @param string[] $variantKeys
     * @return array<string, array{sent: int, clicked: int}>
     */
    private function fetchStats(int $campaignId, array $variantKeys): array
    {
        $connection = $this->resourceConnection->getConnection();
        $messageLogTable = $this->resourceConnection->getTableName('ordo_message_log');
        $messageLogEventTable = $this->resourceConnection->getTableName('ordo_message_log_event');

        /** @var array<string, string|int> $sentRows */
        $sentRows = $connection->fetchPairs(
            $connection->select()
                ->from($messageLogTable, ['variant', 'sent' => 'COUNT(*)'])
                ->where('campaign_id = ?', $campaignId)
                ->where('variant IN (?)', $variantKeys)
                ->group('variant')
        );

        /** @var array<string, string|int> $clickedRows */
        $clickedRows = $connection->fetchPairs(
            $connection->select()
                ->from(
                    ['ml' => $messageLogTable],
                    ['variant', 'clicked' => 'COUNT(DISTINCT ml.entity_id)']
                )
                ->joinInner(
                    ['mle' => $messageLogEventTable],
                    'mle.message_log_id = ml.entity_id',
                    []
                )
                ->where('ml.campaign_id = ?', $campaignId)
                ->where('ml.variant IN (?)', $variantKeys)
                ->where('mle.event_type = ?', MessageLogEvent::TYPE_CLICKED)
                ->group('ml.variant')
        );

        $stats = [];
        foreach ($variantKeys as $key) {
            $stats[$key] = [
                'sent' => (int) ($sentRows[$key] ?? 0),
                'clicked' => (int) ($clickedRows[$key] ?? 0),
            ];
        }

        return $stats;
    }

    /**
     * Same {variant key => stats} shape as fetchStats(), but sourced from
     * CampaignOutcomeLogger::getStats() (sent/converted/conversion_rate/revenue, already grouped
     * by campaign_id+variant) for the conversion-rate/revenue metrics - a deliberately separate
     * "sent" count from fetchStats()'s own (ordo_campaign_outcome_log vs. ordo_message_log), so
     * the minimum-sample-size gate always matches whichever table the chosen metric actually
     * reads from.
     *
     * @param string[] $variantKeys
     * @return array<string, array{sent: int, conversion_rate: float, revenue: float}>
     */
    private function fetchOutcomeStats(int $campaignId, array $variantKeys): array
    {
        $byVariant = [];
        foreach ($this->campaignOutcomeLogger->getStats($campaignId) as $row) {
            if ($row['variant'] !== null) {
                $byVariant[$row['variant']] = $row;
            }
        }

        $stats = [];
        foreach ($variantKeys as $key) {
            $stats[$key] = [
                'sent' => $byVariant[$key]['sent'] ?? 0,
                'conversion_rate' => $byVariant[$key]['conversion_rate'] ?? 0.0,
                'revenue' => $byVariant[$key]['revenue'] ?? 0.0,
            ];
        }

        return $stats;
    }
}
