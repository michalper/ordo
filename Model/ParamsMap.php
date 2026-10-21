<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * A campaign/segment child row's params JSON is conceptually always a key-value map, never a
 * list - but json_decode() happily returns lists, scalars, or arrays with numeric keys from a
 * hand-written API/DB payload. Normalizing once, at the decode boundary, is what lets every
 * consumer (CampaignDispatcher, SegmentMatcher, GroupWalker, SegmentMemberResolver, ...) trust
 * array<string, mixed> without keeping its own copy of this filter - three private copies of it
 * existed before this class.
 */
class ParamsMap
{
    /**
     * @return array<string, mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value normalizer called from AbstractModel::getParams() implementations, where constructor injection isn't available; nothing to intercept
    public static function fromJson(string $json): array
    {
        return self::stringKeyed($json !== '' ? json_decode($json, true) : []);
    }

    /**
     * For a params blob nested inside another row's own JSON (a split variant's action specs, a
     * 'group' condition's nested items), which never passes through any model's getParams().
     *
     * @return array<string, mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- see fromJson().
    public static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
