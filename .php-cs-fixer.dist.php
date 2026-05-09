<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->notPath([
        'config/bundles.php',
        'config/preload.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        // Behaviour-spec test names like `returns_404_for_an_unknown_provider` are far
        // more readable in PHPUnit's --testdox output than camelCase; keep snake_case.
        'php_unit_method_casing' => false,
    ])
    ->setFinder($finder)
;
