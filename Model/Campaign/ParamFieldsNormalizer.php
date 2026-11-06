<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Shared by CampaignSaveProcessor/SegmentSaveProcessor - the JSON-textarea-plus-dedicated-fields
 * merge every condition/action/trigger row's posted data goes through: starts from whatever was
 * typed in the JSON textarea (if valid), then overlays whichever of $fields are present on the
 * row — dedicated fields win over the JSON textarea on key conflicts, since a stale/copy-pasted
 * JSON blob shouldn't silently override a field the admin just filled in and can see.
 *
 * $fields now comes directly from ConditionInterface::getParamFields()/ActionInterface::
 * getParamFields() (or, for triggers, a small hand-wrapped list - triggers have no extensible
 * Pool) instead of each save processor keeping its own hand-maintained field-name list in sync
 * with the other and with Block\Adminhtml\Campaign\Edit\Flow by hand - the two real bugs that
 * caused in this codebase's own history: a flat field list leaking an unrelated field into every
 * row regardless of type, and a dedicated field silently missing from one of the
 * hand-maintained lists entirely.
 */
class ParamFieldsNormalizer
{
    /**
     * @param array<string, mixed> $row
     * @param array<int, array{name: string, label?: string, notice?: string, options?: array<int|string, string>, type?: string}> $fields
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value normalizer, nothing to intercept
    public static function normalizeRowParams(array $row, array $fields): string
    {
        $params = self::parseJson((string) ($row['params_json'] ?? ''));

        foreach ($fields as $field) {
            $name = $field['name'];
            $value = trim((string) ($row[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            if (isset($field['type'])) {
                // A dedicated field with its own widget type (tag_multiselect, variant_list) posts
                // JSON, not a plain scalar - every plain text/select field has no 'type' key at
                // all (see ConditionInterface::getParamFields()'s own docblock).
                $decoded = json_decode($value, true);
                $params[$name] = is_array($decoded) ? $decoded : [];
                continue;
            }

            $params[$name] = $value;
        }

        // json_encode([]) is the JSON array literal "[]", not the empty JSON OBJECT "{}" a
        // condition/action/trigger's params conceptually are (a key-value map, not a list) -
        // forced explicitly rather than relying on json_encode's own empty-array behavior, which
        // flips to "[]" the moment $params has zero keys.
        return $params === [] ? '{}' : (json_encode($params) ?: '{}');
    }

    /**
     * Falls back to an empty object rather than rejecting the save outright — a condition/
     * action/trigger with unparsable params simply won't find the keys it expects at dispatch/
     * match time (fails closed, doesn't crash).
     *
     * @return array<string, mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value normalizer, nothing to intercept
    public static function parseJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
