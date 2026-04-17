<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        'var',
        'vendor',
        'public/bundles',
    ])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP83Migration' => true,
        '@PHP82Migration:risky' => true,
        '@PHPUnit100Migration:risky' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'final_class' => false,
        'final_public_method_for_abstract_class' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_align' => false,
        'phpdoc_summary' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder)
;
