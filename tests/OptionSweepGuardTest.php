<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\OptionCleanupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-22 — nothing under `bcc_` may be swept from wp_options BY PREFIX.
 *
 * ⚠⚠ WHAT THIS EXISTS TO PREVENT. An `init` hook used to delete every
 * option whose name began with `bcc_disputes_`, `bcc_onchain_` or
 * `bcc_signals_`, on the stated grounds that those plugins had been
 * deleted. They were **MERGED INTO bcc-trust**, and the merged code kept
 * the old option and transient prefixes — so all three prefixes were LIVE:
 *
 *   bcc_disputes_  7 keys (cron last-run/last-success/last-failure, a
 *                  schema `constraint_missing` flag and its
 *                  `admin_notified_missing` guard)
 *   bcc_onchain_   `bcc_onchain_last_success_<chainId>`, written by
 *                  OnchainCircuitBreaker and read by SignalFetcher, plus
 *                  indexer_stats / enrichment_stats / refresh_offset
 *   bcc_signals_   SignalRepository's per-user transient
 *                  `bcc_signals_u<userId>`
 *
 * ⚠ It escaped by TIMING, not design: the sweep had already run once and
 * removed nothing, before any of those keys existed, and its sentinel then
 * made it inert. The keys exist now, so a fresh install — or anything
 * clearing that sentinel — would have deleted all of them.
 *
 * ⭐ WHY A PREFIX RULE RATHER THAN A KEEP-LIST. Two of the three families
 * are generated per chain and per user, so no literal keep-list can
 * enumerate them: a keep-list would look like a guard while letting every
 * newly-named key be swept again. The only safe sweep of anything under
 * `bcc_` names EXACT KEYS.
 *
 * ⚠ `OptionCleanupRepository::deleteByPrefix()` is not the defect and is
 * deliberately still here: it is a correct range delete. This file guards
 * how it is USED.
 */
#[CoversClass(OptionCleanupRepository::class)]
final class OptionSweepGuardTest extends TestCase
{
    /**
     * Call sites exempted from the rule, as `path: what`.
     *
     * ⚠ Empty on purpose. An entry here is a claim that a dynamic or
     * bcc-prefixed sweep is safe, and it needs the reason written next to
     * it — the removed sweep's own comment claimed exactly that and was
     * wrong about all three prefixes.
     *
     * @var list<string>
     */
    private const ALLOWED = [];

    // ── the rule ────────────────────────────────────────

    /**
     * ⚠⚠ THE RULE. A `deleteByPrefix()` call is reported unless its first
     * argument is a STRING LITERAL that is provably not ours.
     *
     * ⭐ IT IS DENY-BY-DEFAULT, and the first version of this guard was not
     * — which is why review rejected it. That version only looked at string
     * literals, so it would have passed a BYTE-FOR-BYTE REVERT of the code
     * it exists to prevent: the removed sweep called
     * `deleteByPrefix($prefix)` inside `foreach ($legacyPrefixes as
     * $prefix)`, and a `T_VARIABLE` argument matched nothing. A guard that
     * misses the exact shape it was written against is worse than no guard,
     * because it reads as protection.
     *
     * So anything that is not a single plain literal — a variable, a
     * concatenation, a constant, a function call — is reported on the
     * grounds that this scan cannot prove what it holds. A legitimate
     * dynamic caller must add itself to {@see ALLOWED} and say why.
     *
     * ⚠ Transient wrappers are stripped before the check. `bcc_signals_u5`
     * is stored as `_transient_bcc_signals_u5`, so a sweep of
     * `_transient_bcc_signals_` does not start with `bcc_` and the first
     * version let it through — the removed code listed exactly those
     * `_transient_` and `_transient_timeout_` twins.
     */
    public function testNothingSweepsABccPrefix(): void
    {
        $offenders = [];
        foreach (self::sourceFiles() as $rel => $code) {
            foreach (self::sweepOffenders($code) as $what) {
                if (in_array($rel . ': ' . $what, self::ALLOWED, true)) {
                    continue;
                }
                $offenders[] = $rel . ': ' . $what;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "a bcc_ option must never be swept by prefix — name exact keys, or\n"
            . "allowlist the call with a reason. Found:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /** And the retired sweep's sentinel is not written anywhere any more. */
    public function testTheRetiredLegacySweepIsGone(): void
    {
        foreach (self::sourceFiles() as $rel => $code) {
            self::assertStringNotContainsString(
                'bcc_core_legacy_cleanup_v1',
                $code,
                $rel . ' still references the retired legacy-sweep sentinel'
            );
        }
    }

    // ── the guard must not be vacuous ─────────────────────────

    /**
     * ⚠⚠ THE FIXTURE THAT MATTERS: the EXACT shape of the removed sweep.
     * Not a convenient literal — the real `foreach` over a prefix array,
     * passing a variable. The first version of this guard passed this, and
     * that is the whole reason this case is written out in full.
     */
    public function testTheDetectorCatchesTheRemovedSweepsOwnShape(): void
    {
        $removed = <<<'PHP'
        <?php
        $legacyPrefixes = [
            'bcc_disputes_',
            'bcc_onchain_',
            '_transient_bcc_signals_',
        ];
        foreach ($legacyPrefixes as $prefix) {
            $deleted = \BCC\Core\Repositories\OptionCleanupRepository::deleteByPrefix($prefix);
        }
        PHP;

        self::assertSame(
            ['deleteByPrefix($prefix) — first argument is not a plain literal'],
            self::sweepOffenders($removed),
            'a byte-for-byte revert of the removed sweep must be caught'
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function caughtShapes(): array
    {
        return [
            'a bare bcc_ literal'        => ["deleteByPrefix('bcc_onchain_');", "'bcc_onchain_'"],
            'a transient twin'           => ["deleteByPrefix('_transient_bcc_signals_');", "'_transient_bcc_signals_'"],
            'a transient timeout twin'   => ["deleteByPrefix('_transient_timeout_bcc_disputes_');", "'_transient_timeout_bcc_disputes_'"],
            'a site transient twin'      => ["deleteByPrefix('_site_transient_bcc_onchain_');", "'_site_transient_bcc_onchain_'"],
            'a concatenation'            => ["deleteByPrefix('bcc_' . \$x);", 'deleteByPrefix(...) — first argument is not a plain literal'],
            'a constant'                 => ["deleteByPrefix(self::SOME_PREFIX);", 'deleteByPrefix(...) — first argument is not a plain literal'],
            'a function call'            => ["deleteByPrefix(prefixFor(\$x));", 'deleteByPrefix(...) — first argument is not a plain literal'],
        ];
    }

    /**
     * ⚠ Every shape that must be reported, including the ones the first
     * version missed. The expectation is asserted rather than just
     * "non-empty", so a detector that reported the wrong thing would fail.
     */
    #[DataProvider('caughtShapes')]
    public function testTheDetectorCatches(string $snippet, string $expected): void
    {
        $found = self::sweepOffenders("<?php\n" . $snippet . "\n");

        self::assertCount(1, $found, 'expected exactly one report for: ' . $snippet);
        self::assertStringContainsString(
            str_contains($expected, 'not a plain literal') ? 'not a plain literal' : $expected,
            $found[0]
        );
    }

    /** ⚠ And it must NOT fire on a sweep of something that is not ours. */
    public function testTheDetectorIgnoresANonBccLiteral(): void
    {
        self::assertSame(
            [],
            self::sweepOffenders("<?php\nOptionCleanupRepository::deleteByPrefix('_transient_someoneelse_');\n")
        );
    }

    /**
     * ⚠ Nor on PROSE. The removal tombstone in `bcc-core.php` names the
     * method and all three prefixes in comments; a detector that read
     * comments would report the very explanation written to prevent this.
     */
    public function testTheDetectorIgnoresCommentsAndDocblocks(): void
    {
        $prose = <<<'PHP'
        <?php
        // deleteByPrefix('bcc_onchain_') used to run here — do not reintroduce.
        /** @see OptionCleanupRepository::deleteByPrefix() with 'bcc_disputes_' */
        PHP;

        self::assertSame([], self::sweepOffenders($prose));
    }

    /**
     * ⚠ Anti-vacuity for the method name: a rename would make the scan
     * match nothing and the rule pass for the wrong reason.
     */
    public function testTheGuardedMethodStillExistsUnderThatName(): void
    {
        self::assertTrue(
            method_exists(OptionCleanupRepository::class, 'deleteByPrefix'),
            'the scanner looks for this exact name; a rename makes the rule vacuous'
        );
    }

    /**
     * ⚠⚠ A SHORTER PREFIX IS BROADER, NOT NARROWER, and the first version
     * of this guard missed every one of these. `deleteByPrefix()` is a RANGE
     * delete, so `''`, `'b'` and `'bcc'` all cover `bcc_*` — `''` covers the
     * entire options table. Caught by asking the containment question both
     * ways round rather than only "does it start with bcc_".
     */
    public function testAShorterPrefixIsAlsoReported(): void
    {
        foreach (["''", "'b'", "'bc'", "'bcc'", "'bcc_'"] as $literal) {
            self::assertSame(
                [$literal],
                self::sweepOffenders("<?php\nOptionCleanupRepository::deleteByPrefix({$literal});\n"),
                'a prefix this broad reaches bcc rows: ' . $literal
            );
        }
    }

    /**
     * ⚠ And an empty prefix is reachable THROUGH a wrapper: stripping
     * `_transient_` leaves nothing, which is the whole options table again.
     */
    public function testAWrapperThatStripsToNothingIsReported(): void
    {
        foreach (["'_transient_'", "'_transient_timeout_'", "'_site_transient_'"] as $literal) {
            self::assertSame(
                [$literal],
                self::sweepOffenders("<?php\nOptionCleanupRepository::deleteByPrefix({$literal});\n"),
                'strips to empty, so it covers everything: ' . $literal
            );
        }
    }

    /**
     * ⚠ CASE-INSENSITIVE, because `option_name`'s collation is. A range
     * delete from `'BCC_'` reaches the same rows as one from `'bcc_'`, so a
     * case-sensitive guard would pass it.
     */
    public function testACaseVariantPrefixIsReported(): void
    {
        foreach (["'BCC_'", "'Bcc_Onchain_'", "'_TRANSIENT_bcc_signals_'"] as $literal) {
            self::assertSame(
                [$literal],
                self::sweepOffenders("<?php\nOptionCleanupRepository::deleteByPrefix({$literal});\n"),
                'collation is case-insensitive: ' . $literal
            );
        }
    }

    /**
     * ⚠⚠ ANTI-VACUITY, and it matters more now than before: broadening the
     * rule in two directions could easily make it report EVERYTHING, which
     * would be a guard nobody can satisfy. A prefix that is genuinely not
     * ours and not broader than ours must still pass — including one that
     * merely shares a letter with us.
     */
    public function testAGenuinelyForeignPrefixStillPasses(): void
    {
        foreach (
            [
                "'_transient_someoneelse_'",
                "'wp_'",
                "'bcd_'",
                "'bccx_'",
                "'peepso_'",
            ] as $literal
        ) {
            self::assertSame(
                [],
                self::sweepOffenders("<?php\nOptionCleanupRepository::deleteByPrefix({$literal});\n"),
                'not ours and not broader than ours: ' . $literal
            );
        }
    }

    // ── the detector ──────────────────────────────────

    /**
     * Call sites of `deleteByPrefix()` that this scan cannot prove are safe.
     *
     * ⚠ DENY BY DEFAULT. A single plain string literal is evaluated; a
     * literal that is not ours is accepted; EVERYTHING ELSE is reported,
     * because a variable or a concatenation could hold anything and the
     * removed sweep is exactly that shape.
     *
     * ⚠ Comments are skipped structurally (`token_get_all()`), never by
     * regex.
     *
     * @return list<string>
     */
    private static function sweepOffenders(string $code): array
    {
        $tokens = token_get_all($code);
        $found  = [];
        $n      = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'deleteByPrefix') {
                continue;
            }

            // ⚠ Skip the DECLARATION. `deleteByPrefix(string $prefix, ...)` is
            // not a call, and a deny-by-default rule reports its parameter
            // list as "not a plain literal" otherwise - which is how this
            // first ran: the guard flagged the very method it guards.
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
                continue;
            }

            // Find the opening paren of this call.
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '(') {
                continue;
            }

            // Collect the first argument's significant tokens.
            $arg   = [];
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $tok = $tokens[$k];

                if (!is_array($tok)) {
                    if ($tok === '(') {
                        $depth++;
                        if ($depth === 1) {
                            continue;
                        }
                    } elseif ($tok === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($tok === ',' && $depth === 1) {
                        break;
                    }
                    $arg[] = $tok;
                    continue;
                }

                if ($tok[0] === T_WHITESPACE || $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    continue;
                }
                $arg[] = $tok;
            }

            if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $arg[0][1];
                if (self::isOurPrefix(trim($literal, "'\""))) {
                    $found[] = $literal;
                }
                continue;
            }

            $shown   = count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_VARIABLE
                ? $arg[0][1]
                : '...';
            $found[] = 'deleteByPrefix(' . $shown . ') — first argument is not a plain literal';
        }

        return $found;
    }

    /**
     * Does this option-name prefix reach bcc data?
     *
     * ⚠ The transient wrappers are stripped first. `bcc_signals_u5` lives in
     * wp_options as `_transient_bcc_signals_u5`, so a sweep of
     * `_transient_bcc_signals_` reaches our data without starting with
     * `bcc_` — the miss that review caught, and exactly what the removed
     * code listed.
     */
    private static function isOurPrefix(string $prefix): bool
    {
        // ⚠ LOWERCASED FIRST. `option_name`'s collation is case-INSENSITIVE,
        // so `DELETE ... WHERE option_name >= 'BCC_'` reaches the same rows a
        // lowercase prefix would. A case-sensitive guard would wave that
        // through.
        $prefix = strtolower($prefix);

        foreach (
            [
                '_site_transient_timeout_',
                '_site_transient_',
                '_transient_timeout_',
                '_transient_',
            ] as $wrapper
        ) {
            if (str_starts_with($prefix, $wrapper)) {
                $prefix = substr($prefix, strlen($wrapper));
                break;
            }
        }

        // ⚠⚠ BOTH DIRECTIONS, because `deleteByPrefix()` is a RANGE DELETE
        // (`option_name >= $prefix AND option_name < $prefix . '~'`), not a
        // `LIKE 'bcc_%'`. A prefix SHORTER than ours still covers our rows:
        //
        //     ''      covers every option in the table
        //     'b'     covers bcc_* along with everything else starting b
        //     'bcc'   covers bcc_* exactly
        //
        // and `'_transient_'` strips to `''` above, so the empty case is
        // reachable through a wrapper too. The first version tested only
        // `str_starts_with($prefix, 'bcc_')` and so passed all four.
        //
        // The second clause is the containment test in reverse: is OUR
        // namespace inside the given prefix? `str_starts_with('bcc_', '')` is
        // true, which is what makes the empty prefix fail closed.
        return str_starts_with($prefix, 'bcc_') || str_starts_with('bcc_', $prefix);
    }

    /**
     * bcc-core's own PHP, comments removed, keyed by relative path.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $root = dirname(__DIR__);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            $out[$rel] = self::stripComments($code);
        }

        self::assertNotSame([], $out, 'anti-vacuity: some source must have been scanned');
        $cache = $out;

        return $out;
    }

    /** Replace comments with blank space, preserving everything else. */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
