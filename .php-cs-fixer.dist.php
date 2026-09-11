<?php
/**
 * PrestaShop coding standard (prestashop/php-dev-tools), with two changes:
 * - no blank line between "<?php" and the license comment (the Addons validator
 *   requires it; @Symfony's blank_line_after_opening_tag would add one);
 * - trailing commas added to multi-line arrays only: in parameter and argument
 *   lists they are PHP 8 syntax, and the shared code also ships in the
 *   PrestaShop 8 line, which runs on PHP 7.2.
 *
 * Run: php vendor/friendsofphp/php-cs-fixer/php-cs-fixer fix
 * The build runs it with --dry-run and stops on any difference.
 */
$rules = array_merge(
    (new PrestaShop\CodingStandards\CsFixer\Config())->getRules(),
    [
        'blank_line_after_opening_tag' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    ]
);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', '_internal', 'build', 'dist', 'translations', 'views', 'tests/fake-api']);

return (new PhpCsFixer\Config('PrestaShop coding standard (no404)'))
    ->setRiskyAllowed(true)
    ->setRules($rules)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder);
