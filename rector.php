<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

/**
 * Check-only in CI (`composer rector-check`, `rector process --dry-run`) — flags dead code and
 * missed PHP 8.2+ modernizations without rewriting anything automatically. Deliberately NOT
 * running type-declaration/strict-typing set lists here: this is a Magento 2 module, and rules
 * that add/narrow property or parameter types can conflict with Magento's own magic getters
 * (AbstractModel::__call), DI-generated interceptors, and plugin method signatures that must
 * match the intercepted class exactly — those are false positives here, not real improvements.
 *
 * Api/ is deliberately NOT in withPaths() below: every service-contract interface exposed via
 * etc/webapi.xml lives there, and Magento's WebAPI reflection
 * (Magento\Framework\Reflection\TypeProcessor) requires explicit @return/@param PHPDoc tags on
 * those methods — even when the native type is already declared — to generate that endpoint's
 * schema at all. Confirmed via a real "setup:install" failure after Rector's "redundant
 * docblock" dead-code rules (RemoveUselessReturnTagRector and friends — there were several,
 * each one only surfacing once the previous was skipped, which is what made "skip the whole
 * directory" the actual fix rather than chasing one rule at a time) stripped several of these:
 * "Method's return type must be specified using @return annotation", failing the whole install.
 * A "redundant" docblock here is a real requirement, not dead documentation.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Block',
        __DIR__ . '/Controller',
        __DIR__ . '/Cron',
        __DIR__ . '/Helper',
        __DIR__ . '/Model',
        __DIR__ . '/Observer',
        __DIR__ . '/Setup',
        __DIR__ . '/Ui',
    ])
    ->withPhpSets(php82: true)
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
    ])
    ->withSkip([
        // Magento's own convention for a plugin's "subject" parameter — Rector's dead-code
        // "unused parameter" rules don't know a plugin method's signature is dictated by the
        // class/method it intercepts, not by what the method body actually uses.
        \Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector::class,
        // array_map('intval', ...)/array_map('strval', ...) -> array_map(intval(...), ...):
        // confirmed via a real PHPStan run that this rewrite breaks strictly at level max —
        // intval(...)/strval(...) as a first-class callable captures a signature (int|string,
        // int $base = 10) that's narrower than array_map's own (callable(mixed): mixed)
        // parameter type, so PHPStan correctly flags it as a real type mismatch, not a false
        // positive. The string-literal callable form has no such issue.
        \Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector::class,
    ]);
