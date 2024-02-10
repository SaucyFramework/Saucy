<?php
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/core', __DIR__ . '/ids', __DIR__ . '/messageStorage', __DIR__ . '/tasks']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
    ])
    ->setFinder($finder);
