<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->name('*.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
        'blank_line_before_statement' => ['statements' => ['return', 'throw']],
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
