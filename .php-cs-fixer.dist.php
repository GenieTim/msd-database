<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude([
        'var',
        'vendor',
        'node_modules',
        'public/build',
    ])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
