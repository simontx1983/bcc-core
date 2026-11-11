<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\OptionCleanupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
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
    // ── the rule ────────────────────────────────────────────────────

    /**
     * ⚠⚠ THE RULE. No call site may hand `deleteByPrefix()` a literal that
     * starts with `bcc_`. A new sweep has to either name exact keys or edit
     * this test — and editing it means reading the history above, which is
     * the entire point.
     */
    public function testNothingSweepsABccPrefix(): void
    {
        $offenders = [];
        foreach (self::sourceFiles() as $rel => $code) {
            foreach (self::bccPrefixSweeps($code) as $literal) {
                $offenders[] = $rel . ': ' . $literal;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "a bcc_ prefix must never be swept by prefix — name exact keys instead. Found:\n  "
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

    // ── the guard must not be vacuous ───────────────────────────────

    /**
     * ⚠⚠ ANTI-VACUITY FOR THE DETECTOR ITSELF. A scanner that found nothing
     * would pass the rule above against any source at all, which is the
     * failure mode that makes an inventory guard worthless. So the detector
     * is run against synthetic code containing exactly what it must catch.
     */
    public function testTheDetectorCatchesAPrefixSweep(): void
    {
        $bad = <<<'PHP'
        <?php
        OptionCleanupRepository::deleteByPrefix('bcc_onchain_');
        PHP;

        self::assertSame(["'bcc_onchain_'"], self::bccPrefixSweeps($bad));
    }

    /** ⚠ And it must not fire on a sweep of something that is NOT ours. */
    public function testTheDetectorIgnoresANonBccPrefix(): void
    {
        $fine = <<<'PHP'
        <?php
        OptionCleanupRepository::deleteByPrefix('_transient_someoneelse_');
        PHP;

        self::assertSame([], self::bccPrefixSweeps($fine));
    }

    /**
     * ⚠ And it must not fire on PROSE. The removal tombstone in
     * `bcc-core.php` names the method and all three prefixes in comments;
     * if the detector read comments it would report the very explanation
     * that exists to prevent this.
     */
    public function testTheDetectorIgnoresCommentsAndDocblocks(): void
    {
        $prose = <<<'PHP'
        <?php
        // deleteByPrefix('bcc_onchain_') used to run here — do not reintroduce.
        /** @see OptionCleanupRepository::deleteByPrefix() with 'bcc_disputes_' */
        PHP;

        self::assertSame([], self::bccPrefixSweeps($prose));
    }

    /**
     * ⚠ Anti-vacuity for the method name: if `deleteByPrefix` were renamed,
     * the scanner would match nothing and the rule would pass for the wrong
     * reason. Pinned, so a rename has to come here and be re-aimed.
     */
    public function testTheGuardedMethodStillExistsUnderThatName(): void
    {
        self::assertTrue(
            method_exists(OptionCleanupRepository::class, 'deleteByPrefix'),
            'the scanner looks for this exact name; a rename makes the rule vacuous'
        );
    }

    // ── the detector ────────────────────────────────────────────────

    /**
     * Literals starting with `bcc_` passed to `deleteByPrefix()`.
     *
     * ⚠ COMMENTS ARE STRIPPED WITH `token_get_all()`, NEVER A REGEX — the
     * tombstone that explains this rule quotes both the method and the
     * prefixes, and a regex would report it.
     *
     * @return list<string>
     */
    private static function bccPrefixSweeps(string $code): array
    {
        $tokens = token_get_all($code);
        $found  = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'deleteByPrefix') {
                continue;
            }

            // Walk forward to the first string literal of this call, stopping
            // at the closing paren so a later unrelated literal is not blamed.
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                $next = $tokens[$j];

                if (!is_array($next)) {
                    if ($next === ')' || $next === ';') {
                        break;
                    }
                    continue;
                }

                if ($next[0] === T_COMMENT || $next[0] === T_DOC_COMMENT || $next[0] === T_WHITESPACE) {
                    continue;
                }

                if ($next[0] === T_CONSTANT_ENCAPSED_STRING) {
                    if (str_starts_with(trim($next[1], "'\""), 'bcc_')) {
                        $found[] = $next[1];
                    }
                    break;
                }
            }
        }

        return $found;
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
