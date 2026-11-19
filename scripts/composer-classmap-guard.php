<?php

declare(strict_types=1);

// CLI only. The plugin deploys ship scripts/ (scripts/.htaccess denies web
// access), so a request for this file must never run it: it prints paths.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Composer classmap guard — the committed autoload metadata must match the tree.
 *
 * ⚠⚠ ONE FILE, TWO REPOS. bcc-core and bcc-trust each carry this file at
 * scripts/composer-classmap-guard.php, and the two copies must stay
 * BYTE-IDENTICAL: keep byte-identical with the bcc-core/bcc-trust copy, and
 * change both in the same pair of PRs. Nothing in it is repo-specific: the
 * project's own roots come from its composer.json, and the fix command from
 * how its vendor/ was generated.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * `vendor/` is committed and shipped `--no-dev`, so the usual way to strip dev
 * dependencies before a commit is `git checkout -- vendor`. That also reverts
 * the regenerated autoload metadata, which is silent: PSR-4 still resolves
 * every class, so nothing fails and nobody notices. The drift only surfaces
 * when something autoloads a class the classmap points at AFTER the file was
 * deleted — then PHP emits an `include(): Failed to open stream` warning from
 * inside the ClassLoader, in production, for a class that is gone.
 *
 * Measured in bcc-trust on 2026-09-20: the committed classmap held 486
 * first-party mappings against 551 in the tree — missing 67 first-party
 * classes, and still pointing at 2 deleted ones (`CosmwasmTickBudget`,
 * removed by PR #261, and `StargazeMarketplaceApi`, removed by PR 7.12 four
 * days earlier). Neither broke anything, and neither was noticed.
 *
 * Measured in bcc-core on 2026-11-19 (F-435): 89 first-party mappings against
 * 100 classes in the tree. 11 were missing, one of them loaded at boot on
 * every request, and none was stale. The PSR-4 fallback hid all of it.
 *
 * ── WHAT IT CHECKS, AND WHAT IT DELIBERATELY DOES NOT ───────────────────
 *   1. Every classmap entry that points INSIDE this project must point at a
 *      file that exists. A mapping to a deleted file is the failure that
 *      produces a production warning. "Inside this project" means under one
 *      of the roots composer.json's `autoload` declares (`psr-4` and
 *      `classmap`), never a directory name guessed from the path: a pattern
 *      such as `/app/` also matches a checkout that merely lives under an
 *      `app/` directory, and matches nothing in a repo whose code is in
 *      `src/`.
 *   2. Every first-party class under the project's PSR-4 roots must appear in
 *      the classmap — but ONLY when `config.optimize-autoloader` is true,
 *      which is what makes Composer classmap those classes in the first place.
 *      Without that setting a PSR-4 class is resolved by path and has no
 *      business being in the classmap, so requiring it would be wrong.
 *
 * Vendor package mappings are outside this guard; Composer generates them from
 * the installed package metadata rather than from this repository's first-party
 * source tree.
 *
 * Exit 0 = the committed metadata matches the tree. Exit 1 = regenerate and
 * commit `vendor/composer/`; the FAIL line prints the exact command. That is
 * `composer dump-autoload --no-dev -o`, plus `--ignore-platform-reqs` when
 * the committed vendor/ has no `composer/platform_check.php`. Without that
 * flag Composer would ADD that file, and a `require` of it in
 * autoload_real.php, to a repo that has never shipped one. Exit 2 = the
 * guard could not run, or would have checked nothing.
 */

$root = dirname(__DIR__);
$fail = [];
$note = static function (string $line): void { echo $line, "\n"; };

$note('  COMPOSER CLASSMAP GUARD');
$note('  ' . str_repeat('─', 68));

// ── Configuration ───────────────────────────────────────────────────────────
$composerJson = $root . '/composer.json';
if (!is_file($composerJson)) {
    $note('  FATAL: composer.json not found');
    exit(2);
}
$config = json_decode((string) file_get_contents($composerJson), true);
if (!is_array($config)) {
    $note('  FATAL: composer.json is not valid JSON');
    exit(2);
}
$psr4 = $config['autoload']['psr-4'] ?? [];
$optimized = ($config['config']['optimize-autoloader'] ?? false) === true;
$note(sprintf('  psr-4 roots: %d   optimize-autoloader: %s', count($psr4), $optimized ? 'true' : 'false'));

// The project's own roots: every psr-4 directory and every classmap entry
// composer.json declares, as absolute '/'-separated prefixes.
$firstPartyRoots = [];
foreach ([$config['autoload']['psr-4'] ?? [], ['' => $config['autoload']['classmap'] ?? []]] as $section) {
    foreach ($section as $dirs) {
        foreach ((array) $dirs as $dir) {
            $firstPartyRoots[] = rtrim(str_replace('\\', '/', $root . '/' . trim((string) $dir, '/\\')), '/') . '/';
        }
    }
}
$isFirstParty = static function (string $path) use ($firstPartyRoots): bool {
    $path = str_replace('\\', '/', $path);
    foreach ($firstPartyRoots as $prefix) {
        // A directory root matches by prefix; a classmap FILE entry, exactly.
        if (str_starts_with($path, $prefix) || $path === rtrim($prefix, '/')) {
            return true;
        }
    }
    return false;
};

// How this repo's vendor/ was generated decides the exact fix command.
$fixCommand = 'composer dump-autoload --no-dev -o'
    . (is_file($root . '/vendor/composer/platform_check.php') ? '' : ' --ignore-platform-reqs');

$classmapFile = $root . '/vendor/composer/autoload_classmap.php';
if (!is_file($classmapFile)) {
    $note('  FATAL: vendor/composer/autoload_classmap.php is missing — vendor is not installed');
    exit(2);
}
/** @var array<string, string> $classmap */
$classmap = require $classmapFile;
$note(sprintf('  classmap entries: %d', count($classmap)));

// ── Check 1: no mapping may point at a deleted first-party file ─────────────
$firstParty = 0;
$missing = [];
foreach ($classmap as $class => $path) {
    // Only this project's own files; vendor packages are Composer's to manage.
    if (!$isFirstParty((string) $path)) {
        continue;
    }
    $firstParty++;
    if (!is_file($path)) {
        $missing[$class] = $path;
    }
}
$note(sprintf('  first-party entries: %d', $firstParty));
if ($firstParty === 0) {
    $note('  FATAL: no first-party entries found — the guard would pass over nothing');
    exit(2);
}
if ($missing !== []) {
    $fail[] = count($missing) === 1
        ? '1 classmap entry points at a file that no longer exists:'
        : sprintf('%d classmap entries point at a file that no longer exists:', count($missing));
    foreach ($missing as $class => $path) {
        $fail[] = '    ' . $class . ' → ' . substr((string) $path, strlen($root) + 1);
    }
}

// ── Check 2: coverage, only where the project asks for an optimized classmap ─
$uncovered = [];
$scanned = 0;
if ($optimized && $psr4 !== []) {
    foreach ($psr4 as $prefix => $dirs) {
        foreach ((array) $dirs as $dir) {
            $base = $root . '/' . trim((string) $dir, '/');
            if (!is_dir($base)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                foreach (declaredTypes((string) file_get_contents($file->getPathname())) as $declared) {
                    // Namespaced name as Composer would record it.
                    if (!array_key_exists($declared, $classmap)) {
                        $uncovered[$declared] = substr($file->getPathname(), strlen($root) + 1);
                    }
                }
            }
        }
    }
    $note(sprintf('  psr-4 files scanned: %d', $scanned));
    if ($scanned === 0) {
        $note('  FATAL: no PSR-4 files scanned — the coverage check would pass over nothing');
        exit(2);
    }
    if ($uncovered !== []) {
        $fail[] = sprintf('%d first-party class%s missing from the optimized classmap:', count($uncovered), count($uncovered) === 1 ? '' : 'es');
        foreach (array_slice($uncovered, 0, 25, true) as $class => $path) {
            $fail[] = '    ' . $class . '  (' . str_replace('\\', '/', $path) . ')';
        }
        if (count($uncovered) > 25) {
            $fail[] = '    …and ' . (count($uncovered) - 25) . ' more';
        }
    }
} else {
    $note('  coverage check SKIPPED: optimize-autoloader is off, so PSR-4 classes are');
    $note('  resolved by path and are not required to appear in the classmap.');
}

$note('  ' . str_repeat('─', 68));
if ($fail !== []) {
    foreach ($fail as $line) {
        echo '  ', $line, "\n";
    }
    echo "\n  FAIL: the committed Composer classmap does not match the tree.\n";
    echo '        Run `' . $fixCommand . "` and commit vendor/composer/.\n";
    exit(1);
}
echo "  PASS: every first-party mapping resolves, and every first-party class is mapped.\n";
exit(0);

/**
 * Fully-qualified class/interface/trait/enum names declared in one PHP source.
 *
 * Token-based, so a name inside a comment, a string or a docblock is not
 * mistaken for a declaration.
 *
 * @return list<string>
 */
function declaredTypes(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $namespace = '';
    $out = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_string($t) && ($t === ';' || $t === '{')) {
                    break;
                }
                if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $namespace .= $t[1];
                }
            }
            continue;
        }

        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        // `Foo::class` is not a declaration.
        $prev = $i - 1;
        while ($prev > 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if (is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
            continue;
        }

        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                $out[] = $namespace !== '' ? $namespace . '\\' . $t[1] : $t[1];
            }
            break; // anonymous class, or something else that is not a named declaration
        }
    }

    return $out;
}
