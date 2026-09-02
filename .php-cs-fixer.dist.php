<?php

/**
 * Automatic formatting, layered on top of (not instead of) phpcs.xml.dist's Magento2 coding
 * standard. PHPCS only detects violations; this actually rewrites the file. Kept deliberately
 * narrow (@PSR12 plus a handful of safe, non-stylistic-opinion rules) so it never fights the
 * Magento2 ruleset already enforced in CI — anything PHPCS has an opinion on (brace placement,
 * annotation structure, etc.) is left alone here.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'Test'])
    ->name('*.php')
    ->notName('registration.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        // Conflicts with this codebase's (and Magento2 coding standard's own) established
        // convention of `<?php` immediately followed by `declare(strict_types=1);` with no
        // blank line between them — phpcs already enforces that shape, so don't fight it here.
        'blank_line_after_opening_tag' => false,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
