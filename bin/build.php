<?php
/**
 * Builds the installable module zips into dist/, one per PrestaShop line.
 *
 *   php bin/build.php                 every gate, then every zip
 *   php bin/build.php --target=ps8    only the PrestaShop 8 zip (ps9 likewise)
 *   php bin/build.php --no-tests      skip PHPUnit (the other gates still run)
 *
 * Targets — platform/<target>/target.json holds version, PrestaShop range, PHP floor:
 *   ps9  PrestaShop 9 line (2.x): the source tree itself.
 *   ps8  PrestaShop 8 line (1.x): the source tree with platform/ps8/files/ laid
 *        over it, then its version, range and PHP floor stamped in.
 * An older PrestaShop line is always numbered below a newer one, so a store that
 * moves from PrestaShop 8 to 9 is offered the 2.x zip as a module upgrade.
 *
 * Gates — any failure STOPS the build, nothing half-built reaches dist/:
 *   1. versions: the source tree (src/Version.php, config.xml, the no404.php
 *      literals, the composer.json PHP floor) = the ps9 target; lines numbered in
 *      order; a CHANGELOG entry per target; upgrade scripts well named, none for
 *      a future version, one for every version after 1.0.0;
 *   2. translations complete and up to date (bin/translations.php --check);
 *   3. coding standard (php-cs-fixer --dry-run, PrestaShop rules);
 *   4. PHPUnit;
 *   5. on each staged copy: stamped values = target; index.php in every folder,
 *      _PS_VERSION_ guard and AFL license header in every PHP file, license
 *      header in every template, no forbidden function, no override/, logo
 *      present, vendor/ holds the autoloader only; compatibility with the
 *      target's PHP floor (PHPCompatibility), plus a real lint when
 *      NO404_PHP_LINT_<TARGET> names a PHP binary of that version
 *      (e.g. NO404_PHP_LINT_PS8=C:\php-7.2\php.exe).
 *
 * Only an explicit whitelist is staged, so _internal/, tests/, bin/, dist/,
 * platform/, dev tooling and dev dependencies can never end up in a zip.
 *
 * Development tool; not shipped in the module zip.
 */
const MODULE = 'no404';
const LICENSE_MARK = 'Academic Free License version 3.0';

/** Build targets, newest PrestaShop line first. */
const TARGETS = ['ps9', 'ps8'];

/** The target the source tree itself represents. */
const SOURCE_TARGET = 'ps9';

/** What goes into the zip. Nothing else. */
const WHITELIST = [
    'no404.php', 'index.php', 'config.xml', 'logo.png', 'LICENSE', 'README.md',
    'CHANGELOG.md', '.htaccess', 'composer.json',
    'src', 'config', 'views', 'translations', 'upgrade',
];

/** Patterns the Addons validator rejects (word-boundary so curl_exec( does not match exec(). */
const FORBIDDEN = [
    '/\beval\s*\(/' => 'eval()',
    '/\b(?<!json_)(un)?serialize\s*\(/' => 'serialize()/unserialize()',
    '/(?<![\w>:])exec\s*\(/' => 'exec()',
    '/\bshell_exec\s*\(/' => 'shell_exec()',
    '/(?<![\w>:])system\s*\(/' => 'system()',
    '/\bpassthru\s*\(/' => 'passthru()',
    '/\bpopen\s*\(/' => 'popen()',
    '/\bproc_open\s*\(/' => 'proc_open()',
    '/\bbase64_decode\s*\(/' => 'base64_decode()',
    '/\bcreate_function\s*\(/' => 'create_function()',
];

/** The literals bin/build.php reads and stamps: [file, pattern with the value in group 1]. */
const VERSION_PATTERN = "/CURRENT = '([^']+)'/";
const CONFIG_XML_PATTERN = '#<version><!\[CDATA\[([^\]]+)\]\]></version>#';
// The Addons validator reads these two without running the file: literals only.
const MODULE_VERSION_PATTERN = "/\\\$this->version = '([^']+)';/";
const COMPLIANCY_PATTERN = "/\\\$this->ps_versions_compliancy = \\['min' => '([^']+)', 'max' => '([^']+)'\\];/";
const PHP_FLOOR_PATTERN = '/"php": ">=([^"]+)"/';

$root = dirname(__DIR__);
chdir($root);
$skipTests = in_array('--no-tests', $argv, true);
$selected = TARGETS;
foreach ($argv as $arg) {
    if (0 === strpos($arg, '--target=')) {
        $selected = [substr($arg, strlen('--target='))];
    }
}

function stop(string $message): void
{
    fwrite(STDERR, PHP_EOL . 'BUILD STOPPED: ' . $message . PHP_EOL);
    exit(1);
}

function step(string $message): void
{
    echo PHP_EOL . '==> ' . $message . PHP_EOL;
}

function run(string $command): void
{
    passthru($command, $code);
    if (0 !== $code) {
        stop("command failed (exit $code): $command");
    }
}

function php(): string
{
    return escapeshellarg(PHP_BINARY);
}

/** @return string[] relative paths of every file under $dir */
function files_under(string $dir): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
    }
    sort($out);

    return $out;
}

function remove_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                remove_tree($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function copy_tree(string $from, string $to): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) {
            mkdir($to, 0775, true);
        }
        foreach (scandir($from) as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                copy_tree($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    } else {
        copy($from, $to);
    }
}

/** @return array{label: string, version: string, ps_min: string, ps_max: string, php_min: string} */
function target_manifest(string $target): array
{
    $file = "platform/$target/target.json";
    if (!is_file($file)) {
        stop("unknown target \"$target\": no $file");
    }
    $manifest = json_decode((string) file_get_contents($file), true);
    foreach (['label', 'version', 'ps_min', 'ps_max', 'php_min'] as $key) {
        if (!is_array($manifest) || !isset($manifest[$key]) || !is_string($manifest[$key]) || '' === $manifest[$key]) {
            stop("$file: \"$key\" is missing");
        }
    }
    if (!preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])) {
        stop("$file: version must be x.y.z");
    }

    return $manifest;
}

/** The value in group $group of the single match of $pattern in $file, or stop. */
function read_literal(string $file, string $pattern, int $group = 1): string
{
    if (!preg_match($pattern, (string) file_get_contents($file), $m)) {
        stop("$file: no match for $pattern");
    }

    return $m[$group];
}

/** Stops unless the tree in $dir carries exactly the values of $manifest. */
function assert_tree_matches(string $dir, array $manifest, string $where): void
{
    $found = [
        'src/Version.php CURRENT' => [read_literal("$dir/src/Version.php", VERSION_PATTERN), $manifest['version']],
        'config.xml <version>' => [read_literal("$dir/config.xml", CONFIG_XML_PATTERN), $manifest['version']],
        'no404.php $this->version' => [read_literal("$dir/no404.php", MODULE_VERSION_PATTERN), $manifest['version']],
        'no404.php compliancy min' => [read_literal("$dir/no404.php", COMPLIANCY_PATTERN, 1), $manifest['ps_min']],
        'no404.php compliancy max' => [read_literal("$dir/no404.php", COMPLIANCY_PATTERN, 2), $manifest['ps_max']],
        'composer.json PHP floor' => [read_literal("$dir/composer.json", PHP_FLOOR_PATTERN), $manifest['php_min']],
    ];
    foreach ($found as $what => $pair) {
        if ($pair[0] !== $pair[1]) {
            stop("$where: $what is {$pair[0]}, the target says {$pair[1]}");
        }
    }
}

/** Replaces the single match of $pattern in $file with $replacement, taken literally. */
function stamp(string $file, string $pattern, string $replacement): void
{
    $count = 0;
    $out = preg_replace_callback($pattern, static function () use ($replacement) {
        return $replacement;
    }, (string) file_get_contents($file), -1, $count);
    if (null === $out || 1 !== $count) {
        stop("cannot stamp $file: expected one match of $pattern, found $count");
    }
    file_put_contents($file, $out);
}

/** Stages, checks and zips one target. Returns the summary line. */
function build_target(string $root, string $target, array $manifest, string $phpcs): string
{
    $tag = "[$target] ";

    step($tag . 'Staging the whitelist');
    $build = $root . '/build';
    $stage = $build . '/' . MODULE;
    remove_tree($build);
    mkdir($stage, 0775, true);
    foreach (WHITELIST as $entry) {
        if (!file_exists($entry)) {
            stop("whitelisted file missing: $entry");
        }
        copy_tree($root . '/' . $entry, $stage . '/' . $entry);
    }

    // Per-target replacements. Each one must replace a file that exists, so a
    // renamed source file cannot leave a stale replacement behind unnoticed.
    $overlay = "platform/$target/files";
    if (is_dir($overlay)) {
        $replaced = files_under($root . '/' . $overlay);
        foreach ($replaced as $file) {
            if (!is_file($stage . '/' . $file)) {
                stop("$overlay/$file replaces nothing in the staged copy");
            }
            copy($root . '/' . $overlay . '/' . $file, $stage . '/' . $file);
        }
        echo count($replaced) . " file(s) replaced from $overlay/" . PHP_EOL;
    }

    $version = $manifest['version'];
    stamp("$stage/src/Version.php", VERSION_PATTERN, "CURRENT = '$version'");
    stamp("$stage/config.xml", CONFIG_XML_PATTERN, '<version><![CDATA[' . $version . ']]></version>');
    stamp("$stage/no404.php", MODULE_VERSION_PATTERN, '$this->version = \'' . $version . '\';');
    stamp("$stage/no404.php", COMPLIANCY_PATTERN, '$this->ps_versions_compliancy = [\'min\' => \'' . $manifest['ps_min'] . '\', \'max\' => \'' . $manifest['ps_max'] . '\'];');
    stamp("$stage/composer.json", PHP_FLOOR_PATTERN, '"php": ">=' . $manifest['php_min'] . '"');
    foreach (glob("$stage/upgrade/upgrade-*.php") ?: [] as $script) {
        if (preg_match('/upgrade-(\d+\.\d+\.\d+)\.php$/', $script, $m) && version_compare($m[1], $version, '>')) {
            unlink($script); // Belongs to a newer line.
        }
    }
    assert_tree_matches($stage, $manifest, "staged $target copy");
    printf('%s %s: PrestaShop %s – %s, PHP %s+%s', $manifest['label'], $version, $manifest['ps_min'], $manifest['ps_max'], $manifest['php_min'], PHP_EOL);

    // Production autoloader only: the module has no runtime dependency; symfony/cache
    // and psr/cache come with PrestaShop itself.
    run('composer dump-autoload --no-dev --optimize --no-interaction --working-dir=' . escapeshellarg($stage));
    foreach (scandir($stage . '/vendor') as $entry) {
        if (!in_array($entry, ['.', '..', 'autoload.php', 'composer'], true)) {
            stop("vendor/ must hold the autoloader only, found: vendor/$entry");
        }
    }
    foreach (files_under($stage . '/vendor/composer') as $file) {
        if (preg_match('#^(vendor|phpunit|symfony|friendsofphp)#', $file)) {
            stop("unexpected file in vendor/composer: $file");
        }
    }
    $classmap = (string) file_get_contents($stage . '/vendor/composer/autoload_classmap.php');
    if (false !== strpos($classmap, '\\\\Tests\\\\') || false !== strpos($classmap, 'PHPUnit')) {
        stop('the autoloader references test or dev classes');
    }

    // index.php in every folder (Addons requirement), vendor/ included.
    $indexTemplate = (string) file_get_contents($root . '/index.php');
    $added = 0;
    $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $allDirs = [$stage];
    foreach ($dirs as $dir) {
        if ($dir->isDir()) {
            $allDirs[] = $dir->getPathname();
        }
    }
    foreach ($allDirs as $dir) {
        if (!is_file($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', $indexTemplate);
            ++$added;
        }
    }
    echo "index.php added to $added folder(s)" . PHP_EOL;

    step($tag . 'Static checks on the staged copy');
    $problems = [];
    foreach (['_internal', 'tests', 'bin', 'dist', 'build', 'platform', 'override', '.git', 'node_modules'] as $banned) {
        if (file_exists($stage . '/' . $banned)) {
            $problems[] = "must not be shipped: $banned/";
        }
    }
    $logo = @getimagesize($stage . '/logo.png');
    if (!is_array($logo) || $logo[0] < 32 || $logo[0] !== $logo[1]) {
        $problems[] = 'logo.png must be a square PNG of at least 32×32';
    }
    foreach (files_under($stage) as $file) {
        $path = $stage . '/' . $file;
        if (0 === strpos($file, 'vendor/')) {
            continue; // Generated by Composer.
        }
        $source = (string) file_get_contents($path);
        if (preg_match('/\.php$/', $file)) {
            if (false === strpos($source, LICENSE_MARK)) {
                $problems[] = "$file: missing AFL license header";
            }
            if ('index.php' !== basename($file) && false === strpos($source, "defined('_PS_VERSION_')")) {
                $problems[] = "$file: missing the _PS_VERSION_ guard";
            }
            // Comments included: the Addons validator flags a forbidden name even there.
            foreach (FORBIDDEN as $pattern => $name) {
                if (preg_match($pattern, $source)) {
                    $problems[] = "$file: forbidden $name (comments count too)";
                }
            }
            if (preg_match('/^<\?php\r?\n[ \t]*\r?\n/', $source)) {
                $problems[] = "$file: blank line before the file comment";
            }
            if (0 === strpos($file, 'src/Controller/') && preg_match('/\bContext::getContext\s*\(/', $source)) {
                $problems[] = "$file: legacy Context in a Symfony controller";
            }
            // PrestaShop 8 parses docblocks: "@AdminSecurity" in prose becomes a
            // rule with an empty expression and the page returns HTTP 500.
            if (0 === strpos($file, 'src/Controller/')
                && preg_match_all('/@AdminSecurity\b/', $source) !== preg_match_all('/^[ \t]*\*[ \t]*@AdminSecurity\(/m', $source)) {
                $problems[] = "$file: @AdminSecurity outside an annotation line (PrestaShop 8 reads it as an empty rule)";
            }
        } elseif (preg_match('/\.(twig|tpl|js)$/', $file) && false === strpos($source, LICENSE_MARK)) {
            $problems[] = "$file: missing AFL license header";
        }
    }
    if ($problems) {
        echo implode(PHP_EOL, array_map(static function ($p) {
            return '  - ' . $p;
        }, $problems)) . PHP_EOL;
        stop(count($problems) . ' problem(s) in the staged copy');
    }
    echo 'ok' . PHP_EOL;

    step($tag . "PHP {$manifest['php_min']} compatibility");
    // Our files strictly, warnings included. Composer's generated autoloader for
    // errors only: the question there is whether the PHP floor can load it, not
    // what a later PHP deprecates (its trigger_error(E_USER_ERROR) warns for 8.4).
    $compat = php() . ' ' . $phpcs . ' -q --standard=PHPCompatibility --extensions=php --runtime-set testVersion '
        . escapeshellarg($manifest['php_min'] . '-');
    run($compat . ' --ignore=' . escapeshellarg('*/vendor/*') . ' ' . escapeshellarg($stage));
    run($compat . ' -n ' . escapeshellarg($stage . '/vendor'));
    echo 'PHPCompatibility ok' . PHP_EOL;
    $lintBinary = (string) getenv('NO404_PHP_LINT_' . strtoupper($target));
    if ('' === $lintBinary) {
        echo 'real lint skipped: set NO404_PHP_LINT_' . strtoupper($target) . " to a PHP {$manifest['php_min']} binary" . PHP_EOL;
    } else {
        $lintVersion = trim((string) shell_exec(escapeshellarg($lintBinary) . ' -r "echo PHP_VERSION;"'));
        if (0 !== strpos($lintVersion, $manifest['php_min'] . '.')) {
            stop('NO404_PHP_LINT_' . strtoupper($target) . " is PHP '$lintVersion', expected {$manifest['php_min']}.x");
        }
        $linted = 0;
        foreach (files_under($stage) as $file) {
            if (preg_match('/\.php$/', $file)) {
                $output = [];
                exec(escapeshellarg($lintBinary) . ' -l ' . escapeshellarg($stage . '/' . $file) . ' 2>&1', $output, $code);
                if (0 !== $code) {
                    stop("PHP $lintVersion cannot parse $file: " . implode(' ', $output));
                }
                ++$linted;
            }
        }
        echo "lint ok: $linted file(s) with PHP $lintVersion" . PHP_EOL;
    }

    step($tag . 'Zip');
    // This target's previous zips, and the unsuffixed ones of the one-zip era.
    foreach (glob($root . '/dist/' . MODULE . '-*.zip*') ?: [] as $old) {
        if (preg_match('/^' . MODULE . '-\d+\.\d+\.\d+(-' . preg_quote($target, '/') . ')?\.zip(\.sha256)?$/', basename($old))) {
            unlink($old);
        }
    }
    $zipPath = $root . '/dist/' . MODULE . '-' . $version . '-' . $target . '.zip';
    $zip = new ZipArchive();
    if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        stop("cannot write $zipPath");
    }
    $zip->addEmptyDir(MODULE);
    foreach (files_under($stage) as $file) {
        $zip->addFile($stage . '/' . $file, MODULE . '/' . $file);
    }
    $count = $zip->numFiles;
    $zip->close();

    $hash = hash_file('sha256', $zipPath);
    file_put_contents($zipPath . '.sha256', $hash . '  ' . basename($zipPath) . PHP_EOL);
    remove_tree($build);

    return sprintf('%s  (%d entries, %.1f KB)  sha256 %s', 'dist/' . basename($zipPath), $count, filesize($zipPath) / 1024, $hash);
}

// --------------------------------------------------------------- 1. versions
step('Versions');
$manifests = [];
foreach (TARGETS as $target) {
    $manifests[$target] = target_manifest($target);
}
foreach ($selected as $target) {
    if (!isset($manifests[$target])) {
        stop("unknown target \"$target\" (known: " . implode(', ', TARGETS) . ')');
    }
}
assert_tree_matches($root, $manifests[SOURCE_TARGET], 'source tree (it is the ' . SOURCE_TARGET . ' target)');
for ($i = 1; $i < count(TARGETS); ++$i) {
    $newer = TARGETS[$i - 1];
    $older = TARGETS[$i];
    if (!version_compare($manifests[$older]['version'], $manifests[$newer]['version'], '<')) {
        stop("$older ({$manifests[$older]['version']}) must be numbered below $newer ({$manifests[$newer]['version']}): "
            . 'a store moving to the newer PrestaShop must see the newer zip as an upgrade');
    }
}
$changelog = (string) file_get_contents('CHANGELOG.md');
foreach ($manifests as $target => $manifest) {
    if (false === strpos($changelog, "## [{$manifest['version']}]")) {
        stop("CHANGELOG.md has no \"## [{$manifest['version']}]\" entry ($target)");
    }
}
$newest = $manifests[SOURCE_TARGET]['version'];
foreach (glob('upgrade/upgrade-*.php') ?: [] as $script) {
    if (!preg_match('/upgrade-(\d+\.\d+\.\d+)\.php$/', $script, $m)) {
        stop("badly named upgrade script: $script");
    }
    if (version_compare($m[1], $newest, '>')) {
        stop("upgrade script for a future version: $script");
    }
    $function = 'upgrade_module_' . str_replace('.', '_', $m[1]);
    if (false === strpos((string) file_get_contents($script), "function $function(")) {
        stop("$script must define $function()");
    }
}
foreach ($manifests as $target => $manifest) {
    $version = $manifest['version'];
    if (version_compare($version, '1.0.0', '>') && !is_file("upgrade/upgrade-$version.php")) {
        stop("missing upgrade/upgrade-$version.php for $target (PrestaShop runs it on \"Upgrade\"; return true if nothing changes)");
    }
    printf('%-4s %s  PrestaShop %s – %s, PHP %s+%s', $target, $version, $manifest['ps_min'], $manifest['ps_max'], $manifest['php_min'], PHP_EOL);
}

// ------------------------------------------------------------- 2–4. quality
step('Translations');
run(php() . ' bin/translations.php --check');

step('Coding standard (php-cs-fixer, dry run)');
$fixer = 'vendor/friendsofphp/php-cs-fixer/php-cs-fixer';
if (!is_file($fixer)) {
    stop('php-cs-fixer is not installed — run composer install');
}
run(php() . ' ' . $fixer . ' fix --dry-run --diff --show-progress=none');

if ($skipTests) {
    step('PHPUnit — SKIPPED (--no-tests)');
} else {
    step('PHPUnit');
    run(php() . ' vendor/phpunit/phpunit/phpunit --colors=never');
}

$phpcs = 'vendor/squizlabs/php_codesniffer/bin/phpcs';
if (!is_file($phpcs)) {
    stop('PHP_CodeSniffer with PHPCompatibility is not installed — run composer install');
}
if (!is_dir($root . '/dist')) {
    mkdir($root . '/dist', 0775, true);
}

// ------------------------------------------------------------ 5. per target
$summary = [];
foreach ($selected as $target) {
    $summary[] = build_target($root, $target, $manifests[$target], $phpcs);
}

step('Done');
echo implode(PHP_EOL, $summary) . PHP_EOL;
