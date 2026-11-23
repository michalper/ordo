<?php

declare(strict_types=1);

/**
 * php-fuzzer target for GroupWalker::walk() - the shared AND/OR/nested-group tree-walk behind
 * every campaign/segment condition evaluation (CampaignDispatcher, SegmentMatcher,
 * SegmentMemberResolver, via ConditionGroupEvaluator/SegmentMemberResolver). Fuzzes exactly the
 * shape that reaches it in production: a JSON condition-list, the same shape an inbound
 * webhook's or an imported campaign's JSON ultimately becomes once decoded (see
 * Controller/Webhook/Receive.php, Model/Campaign/CampaignImporter.php,
 * Model/Campaign/ParamFieldsNormalizer.php).
 *
 * GroupWalker's own docblock says a nested 'group' can be "arbitrarily deep", which first
 * suggested stack-exhaustion via a deeply nested payload as the main thing worth hunting here -
 * checked by hand before writing this target, and it turns out to be a non-issue: PHP's own
 * json_decode() has a default max depth of 512, and each nesting level here costs ~4 JSON levels
 * (object -> "params" object -> "conditions" array -> item object), so anything past ~150 levels
 * never even reaches GroupWalker - json_decode() itself returns null first. At exactly that
 * maximum reachable depth (the deep_chain.txt corpus seed), GroupWalker still runs in a fraction
 * of a millisecond with no stack concern. The real, still-open value of fuzzing this target is
 * everything else: type confusion and malformed-shape edge cases in the tree-walk and
 * BooleanGroupCombineStrategy's combine logic, not depth.
 *
 * No Magento bootstrap needed - GroupWalker/ParamsMap/BooleanGroupCombineStrategy have zero
 * framework dependencies, so the module's own Composer autoloader (composer.json's psr-4 map) is
 * enough on its own.
 *
 * @var PhpFuzzer\Config $config
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ordo\Automation\Model\Condition\BooleanGroupCombineStrategy;
use Ordo\Automation\Model\Condition\GroupWalker;
use Ordo\Automation\Model\ParamsMap;

$walker = new GroupWalker();
$strategy = new BooleanGroupCombineStrategy();

// The leaf resolver (a real ConditionPool lookup + a condition's own isSatisfied()) is out of
// scope here - GroupWalker never calls it for a 'group' spec, only for real leaves, and a
// constant resolver is enough to exercise every branch of the tree-walk itself.
$leafResolver = static fn (array $spec): bool => true;

$config->setTarget(function (string $input) use ($walker, $strategy, $leafResolver): void {
    $decoded = json_decode($input, true);
    if (!is_array($decoded) || $decoded === []) {
        return;
    }

    // Mirrors GroupWalker::walkGroup()'s own top-level shape filtering - GroupWalker::walk() is
    // documented as only ever called with an already-valid, non-empty spec list by its real
    // callers (ConditionGroupEvaluator, SegmentMemberResolver), so feeding it anything looser
    // would just be fuzzing array-shape validation that lives in those callers, not in
    // GroupWalker itself.
    $specs = [];
    foreach ($decoded as $item) {
        if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
            continue;
        }
        $specs[] = ['type' => $item['type'], 'params' => ParamsMap::stringKeyed($item['params'] ?? [])];
    }

    if ($specs === []) {
        return;
    }

    $walker->walk($specs, 'all', $leafResolver, $strategy);
});

// Comfortably covers the deepest shape that can ever reach this target (~150 nesting levels,
// ~9.5KB - see deep_chain.txt and this file's own docblock on json_decode's depth limit) with
// headroom for mutation, without wasting fuzzer budget on inputs that can never even decode.
$config->setMaxLen(16384);
