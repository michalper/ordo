<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * A split action's variant assignment (Model\Campaign\SplitVariantSelector::pickByWeight()) is
 * crc32("{campaignId}:{splitActionId}:customer:{customerId}") % 100 bucketed against each
 * variant's own weight - deterministic, but only computable once the campaign (and its split
 * action's own ordo_campaign_action.entity_id) has actually been saved through the real admin
 * UI, so neither id is known ahead of time. There is also no MFTF conditional/branching action to
 * pick "whichever of these pre-created customers lands in variant X" at the test-authoring level.
 *
 * This closes both gaps in one helper call: given the real campaign id and a delimited list of
 * already-created candidate customers (id + email), it looks up the split action's own real
 * entity_id, replicates SplitVariantSelector's exact algorithm for each candidate, and returns
 * the email of the first one landing in the requested variant - so the test itself never has to
 * branch, only to log in as whichever email comes back. Variant weights are fixed at 50/50 two
 * variants ('a'/'b') to match this suite's own split-action fixture exactly; a mismatch there
 * would make this helper's predictions wrong, not just imprecise, so it's a hardcoded assumption
 * documented here rather than a generic re-implementation of arbitrary variant configs.
 */
class SplitVariantTestHelper extends Helper
{
    use DbConnectionTrait;

    private const int WEIGHT_SCALE = 100;

    /** @var array<int, array{key: string, weight: float}> */
    private const array VARIANTS = [
        ['key' => 'a', 'weight' => 50.0],
        ['key' => 'b', 'weight' => 50.0],
    ];

    /**
     * $campaignIdAndCandidates is "campaignId;id1=email1;id2=email2;..." - a single argument
     * combining both, rather than a separate $campaignId argument alongside this one. Confirmed
     * that mixing a plain {$grabbedVariable} argument with a sibling argument built from
     * $$entity.field$$ substitutions, in the same <helper> call, corrupts the grabbed variable's
     * own substitution (it came through as the literal un-substituted text "{$grabCampaignId}"
     * instead of its real value) - putting everything in one argument, so the whole call needs
     * only the entity-field style of substitution, sidesteps that entirely.
     *
     * @throws \RuntimeException if no candidate lands in the requested variant (astronomically
     *     unlikely with more than a handful of real, distinct customer ids at 50/50 weights)
     */
    public function pickEmailForVariant(
        string $variantKey,
        string $campaignIdAndCandidates
    ): string {
        [$campaignIdString, $candidates] = explode(';', $campaignIdAndCandidates, 2);
        $campaignId = (int) $campaignIdString;
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            "SELECT entity_id FROM ordo_campaign_action WHERE campaign_id = :campaign_id AND type = 'split' LIMIT 1"
        );
        $statement->execute(['campaign_id' => $campaignId]);
        $splitActionId = $statement->fetchColumn();
        if ($splitActionId === false) {
            throw new \RuntimeException("No split action found for campaign #{$campaignId}.");
        }
        $splitActionId = (int) $splitActionId;

        foreach (explode(';', $candidates) as $pair) {
            [$customerId, $email] = explode('=', $pair, 2);
            $customerId = (int) $customerId;
            $bucket = crc32("{$campaignId}:{$splitActionId}:customer:{$customerId}") % self::WEIGHT_SCALE;

            $cumulative = 0.0;
            foreach (self::VARIANTS as $variant) {
                $cumulative += $variant['weight'] / self::WEIGHT_SCALE * self::WEIGHT_SCALE;
                if ($bucket < $cumulative) {
                    if ($variant['key'] === $variantKey) {
                        return $email;
                    }
                    break;
                }
            }
        }

        throw new \RuntimeException(
            "No candidate among the given customers landed in variant \"{$variantKey}\" for campaign #{$campaignId}."
        );
    }
}
