<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/src')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->files()
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['align_double_arrow' => false],
        'no_useless_else' => true,
        'no_useless_return' => false,
        'ordered_imports' => true,
        'phpdoc_to_comment' => false,
    ]);
