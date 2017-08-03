<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/server')
;

return PhpCsFixer\Config::create()
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder)
    ;