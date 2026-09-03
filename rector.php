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
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Api',
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
    ]);
