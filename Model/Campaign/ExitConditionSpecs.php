<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\ParamsMap;

/**
 * Normalizes a campaign's optional exit-condition set — a JSON array of {type, params} specs,
 * the same {@see \Ordo\Automation\Model\Condition\ConditionGroupEvaluator} input shape as a
 * campaign's own entry conditions, but stored as one plain JSON blob directly on ordo_campaign
 * (the exit_conditions column) instead of child rows — see
 * docs/adr/0055-campaign-chain-exit-condition.md for why. There's no per-type dedicated-field UI
 * for this yet (a single JSON textarea in the admin form, see ordo_campaign_form.xml), so unlike
 * ParamFieldsNormalizer there's no dedicated-field merge here to get wrong — malformed rows are
 * simply dropped, same fail-closed philosophy as an unknown condition/action type elsewhere in
 * this module.
 */
class ExitConditionSpecs
{
    /**
     * @return array<int, array{type: string, params: array<string, mixed>}>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value normalizer, nothing to intercept
    public static function decode(?string $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $specs = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['type']) || !is_string($row['type']) || $row['type'] === '') {
                continue;
            }

            $specs[] = ['type' => $row['type'], 'params' => ParamsMap::stringKeyed($row['params'] ?? [])];
        }

        return $specs;
    }

    /**
     * Re-encodes admin-posted JSON into its canonical, validated form for storage — malformed
     * rows are dropped rather than rejecting the whole save (same policy as decode() above), and
     * an empty result normalizes to null, not "[]", so getData('exit_conditions') === null stays
     * the one "nothing configured" signal CampaignDispatcher checks.
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value normalizer, nothing to intercept
    public static function sanitizeForSave(string $raw): ?string
    {
        $specs = self::decode($raw);
        return $specs === [] ? null : (json_encode($specs) ?: null);
    }
}
