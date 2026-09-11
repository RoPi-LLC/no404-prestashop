<?php
/**
 * Builds translations/<locale>/ModulesNo404Admin.<locale>.xlf from the wordings
 * actually used in the code, and fails when they drift apart.
 *
 *   php bin/translations.php          write the xlf files
 *   php bin/translations.php --check  only verify (exit 1 on any mismatch)
 *
 * Source wordings (English) are extracted from:
 *   - PHP: every trans('…', …) call whose domain argument is Modules.No404.Admin
 *     (Symfony order trans($id, $params, $domain) and TranslatorAwareType order
 *     trans($id, $domain, $params) — the first domain-shaped literal wins);
 *   - Twig: every '…'|trans(…, 'Modules.No404.Admin').
 * Translations live in bin/translations/<locale>.php as [english => translated].
 * A wording without a translation, or a translation for a wording that no
 * longer exists, stops the script — same rule as the WordPress plugin's bin/i18n.php.
 *
 * Development tool; not shipped in the module zip.
 */
const DOMAIN = 'Modules.No404.Admin';
const DOMAIN_PATTERN = '/^[A-Z][A-Za-z]+\.[A-Za-z0-9]+(\.[A-Za-z0-9]+)?$/';

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

/** @return array<string, string[]> wording => files */
function extract_php(string $file, array &$found): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        if (!is_array($tokens[$i]) || T_STRING !== $tokens[$i][0] || 'trans' !== $tokens[$i][1]) {
            continue;
        }
        $j = next_significant($tokens, $i + 1);
        if (null === $j || '(' !== $tokens[$j]) {
            continue;
        }
        $j = next_significant($tokens, $j + 1);
        if (null === $j || !is_array($tokens[$j]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[$j][0]) {
            continue;
        }
        $message = php_literal($tokens[$j][1]);

        // Walk the rest of the call; the first domain-shaped literal is the domain.
        $depth = 1;
        $domain = null;
        for ($k = $j + 1; $k < $count && $depth > 0; ++$k) {
            $token = $tokens[$k];
            if ('(' === $token || '[' === $token) {
                ++$depth;
            } elseif (')' === $token || ']' === $token) {
                --$depth;
            } elseif (null === $domain && is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $literal = php_literal($token[1]);
                if (preg_match(DOMAIN_PATTERN, $literal)) {
                    $domain = $literal;
                }
            }
        }

        if (DOMAIN === $domain) {
            $found[$message][] = $file;
        }
    }
}

function next_significant(array $tokens, int $from): ?int
{
    for ($i = $from, $n = count($tokens); $i < $n; ++$i) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

function php_literal(string $literal): string
{
    $quote = $literal[0];
    $body = substr($literal, 1, -1);

    return "'" === $quote ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);
}

function extract_twig(string $file, array &$found): void
{
    $source = (string) file_get_contents($file);
    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\|trans\\(/", $source, $matches, PREG_OFFSET_CAPTURE)) {
        return;
    }
    foreach ($matches[1] as $i => [$message, $offset]) {
        $rest = substr($source, $matches[0][$i][1] + strlen($matches[0][$i][0]));
        if (preg_match_all("/'([^']*)'/", substr($rest, 0, 400), $literals)) {
            foreach ($literals[1] as $literal) {
                if (preg_match(DOMAIN_PATTERN, $literal)) {
                    if (DOMAIN === $literal) {
                        $found[str_replace("\\'", "'", $message)][] = $file;
                    }
                    break;
                }
            }
        }
    }
}

$found = [];
$phpFiles = [$root . '/no404.php'];
// src/ plus the per-target replacements (platform/<target>/files/src): the
// PrestaShop 8 controller carries the same wordings in its own calls.
foreach (array_merge([$root . '/src'], glob($root . '/platform/*/files/src', GLOB_ONLYDIR) ?: []) as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ('php' === $file->getExtension()) {
            $phpFiles[] = $file->getPathname();
        }
    }
}
foreach ($phpFiles as $file) {
    extract_php($file, $found);
}
foreach (glob($root . '/views/templates/admin/*.twig') ?: [] as $file) {
    extract_twig($file, $found);
}
ksort($found);

echo 'Wordings in ' . DOMAIN . ': ' . count($found) . PHP_EOL;

$failed = false;
foreach (glob(__DIR__ . '/translations/*.php') ?: [] as $dictionaryFile) {
    $locale = basename($dictionaryFile, '.php');
    /** @var array<string, string> $dictionary */
    $dictionary = require $dictionaryFile;

    $missing = array_diff(array_keys($found), array_keys($dictionary));
    $stale = array_diff(array_keys($dictionary), array_keys($found));
    $emptyOnes = array_keys(array_filter($dictionary, static function ($t) {
        return '' === trim((string) $t);
    }));

    foreach ($missing as $message) {
        echo "  [$locale] MISSING: $message" . PHP_EOL;
    }
    foreach ($stale as $message) {
        echo "  [$locale] STALE (no longer in the code): $message" . PHP_EOL;
    }
    foreach ($emptyOnes as $message) {
        echo "  [$locale] EMPTY: $message" . PHP_EOL;
    }
    // Placeholders must survive translation.
    foreach ($found as $message => $files) {
        if (!isset($dictionary[$message])) {
            continue;
        }
        preg_match_all('/%[a-z_]+%/', $message, $a);
        preg_match_all('/%[a-z_]+%/', $dictionary[$message], $b);
        $pa = $a[0];
        $pb = $b[0];
        sort($pa);
        sort($pb);
        if ($pa !== $pb) {
            echo "  [$locale] PLACEHOLDERS DIFFER: $message" . PHP_EOL;
            $failed = true;
        }
    }
    if ($missing || $stale || $emptyOnes) {
        $failed = true;
    }

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->setIndentString('  ');
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('xliff');
    $xml->writeAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');
    $xml->writeAttribute('version', '1.2');
    $xml->startElement('file');
    $xml->writeAttribute('original', 'modules/no404');
    $xml->writeAttribute('source-language', 'en-US');
    $xml->writeAttribute('target-language', $locale);
    $xml->writeAttribute('datatype', 'plaintext');
    $xml->startElement('body');
    foreach (array_keys($found) as $message) {
        if (!isset($dictionary[$message])) {
            continue;
        }
        $xml->startElement('trans-unit');
        $xml->writeAttribute('id', md5($message));
        $xml->writeElement('source', $message);
        $xml->writeElement('target', $dictionary[$message]);
        $xml->endElement();
    }
    $xml->endElement();
    $xml->endElement();
    $xml->endElement();
    $xml->endDocument();
    $content = $xml->outputMemory();

    $target = sprintf('%s/translations/%s/ModulesNo404Admin.%s.xlf', $root, $locale, $locale);
    if ($check) {
        if (!is_file($target) || file_get_contents($target) !== $content) {
            echo "  [$locale] OUT OF DATE: $target — run php bin/translations.php" . PHP_EOL;
            $failed = true;
        }
    } else {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $content);
        echo "  [$locale] wrote " . count(array_intersect_key($dictionary, $found)) . ' units → ' . substr($target, strlen($root) + 1) . PHP_EOL;
    }
}

exit($failed ? 1 : 0);
