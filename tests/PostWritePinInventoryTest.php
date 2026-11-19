<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-361 — EVERY post write in bcc-core goes through
 * `PeepSoAddPostGuard::pinOnly()`.
 *
 * ── WHY A MECHANICAL INVARIANT AND NOT A JUDGMENT ───────────────────
 *
 * The first pass at F-361 scoped itself to "writes reachable from a
 * member's request". That needs a call per site, and the call was wrong
 * twice: `wp_update_post()` in a review-reply publish was missed because
 * it is not spelled `wp_insert_post`, and a comment TRASH was missed
 * because a trash does not look like a write. Both reach the same three
 * request-reading listeners.
 *
 * "Every BCC post write is pinned" needs no judgment, so it can be
 * checked. That is the whole reason this test exists and the reason
 * {@see ALLOWLIST} is EMPTY — an allowlist naming the sites a guard
 * exists to catch is a green that means nothing.
 *
 * ── WHAT COUNTS AS A POST WRITE ─────────────────────────────────────
 *
 * Anything that reaches the `wp_insert_post` ACTION, which is where
 * `PeepSoMoods::save_mood` (moods.php:47),
 * `PeepSoLocation::insert_post` (location.php:90) and
 * `PeepSo3_Anon::save_anon_op` (peepso-anon) are registered. ⚠ Every
 * entry in {@see WRITERS} was confirmed by reading WordPress's source;
 * the indirect ones are precisely why a grep for "wp_insert_post" is
 * not a sufficient guard.
 *
 * ⚠⚠ TOKENS, NOT TEXT. A comment or docblock that NAMES a writer is not
 * a call, and these trees carry more mentions than calls — measured: a
 * `substr_count` of `PeepSoAddPostGuard::pinOnly(` read 3 for 2 calls,
 * because the comment explaining the guard named it. `token_get_all()`
 * returns comments as single T_COMMENT / T_DOC_COMMENT tokens, so a
 * token walk cannot make that mistake.
 */
final class PostWritePinInventoryTest extends TestCase
{
    /**
     * Functions whose call reaches the `wp_insert_post` action.
     *
     * Each line cites the WordPress source that proves it.
     */
    private const WRITERS = [
        // fires the action itself
        'wp_insert_post'                => 'post.php:5204 — do_action(\'wp_insert_post\')',
        'wp_publish_post'               => 'post.php:5370 — fires it after a direct $wpdb->update',
        // one hop
        'wp_update_post'                => 'calls wp_insert_post()',
        'wp_insert_attachment'          => 'post.php:6665 — returns wp_insert_post()',
        'wp_trash_post'                 => 'calls wp_update_post() in its body',
        'wp_untrash_post'               => 'calls wp_update_post() in its body',
        'media_handle_upload'           => 'media.php:133 — wp_insert_attachment()',
        'media_handle_sideload'         => 'media.php:64 — wp_insert_attachment()',
        'check_and_publish_future_post' => 'calls wp_publish_post()',
        // two hops
        'media_sideload_image'          => 'media.php:46 — media_handle_sideload()',
    ];

    /** Indirect dispatch: these take a callable NAME as a string. */
    private const DISPATCHERS = ['call_user_func', 'call_user_func_array'];

    /**
     * ⛔ DELIBERATELY EMPTY, and it must stay that way.
     *
     * Adding an entry here is the thing this test exists to prevent. If a
     * new write genuinely cannot be pinned, that needs a decision and a
     * stated reason in review — not a quiet line in a test fixture.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    /** Directories this guard polices. bcc-core owns its own tree only. */
    private const ROOTS = ['src'];

    /**
     * ⛔ VACUITY FLOOR. A fresh worktree with the tree missing makes every
     * scan below pass over nothing — the false green that bit the umbrella
     * guards, where a worktree with no plugin checkouts let them "pass".
     * Fewer files than this means the scan is not measuring anything.
     */
    private const MIN_FILES = 50;

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $base  = dirname(__DIR__);
        $files = [];

        foreach (self::ROOTS as $dir) {
            $root = $base . '/' . $dir;
            self::assertDirectoryExists($root, "guarded root {$dir} is missing");

            // ⚠ SKIP_DOTS is load-bearing: without it the iterator yields
            // `.` and recurses into it forever.
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every post-write call site, with whether a `pinOnly(` is still open
     * around it.
     *
     * @param list<string> $files
     * @return list<array{site: string, fn: string, pinned: bool}>
     */
    private static function callSites(array $files): array
    {
        $base = dirname(__DIR__);
        $rows = [];

        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count  = count($tokens);
            $rel    = str_replace('\\', '/', substr($file, strlen($base) + 1));

            $depth    = 0;
            $pinStack = [];
            $line     = 1;

            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];

                if (is_array($t)) {
                    $line = $t[2];

                    // call_user_func('wp_insert_post', …) and friends: the
                    // writer's name appears as a STRING, not a T_STRING.
                    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $literal = trim($t[1], "'\"\\");
                        if (isset(self::WRITERS[$literal])) {
                            $rows[] = [
                                'site'   => $rel . ':' . $line,
                                'fn'     => $literal . ' (as a string)',
                                'pinned' => $pinStack !== [],
                            ];
                        }
                    }
                }

                if ($t === '(') {
                    $depth++;
                    continue;
                }
                if ($t === ')') {
                    while ($pinStack !== [] && end($pinStack) >= $depth) {
                        array_pop($pinStack);
                    }
                    $depth--;
                    continue;
                }

                // ⚠⚠ THREE NAME TOKENS, NOT ONE. In PHP 8 a fully-qualified
                // call is a SINGLE token: `\wp_trash_post(` lexes as one
                // T_NAME_FULLY_QUALIFIED whose text includes the backslash —
                // NOT as T_NS_SEPARATOR followed by T_STRING, as it did in
                // PHP 7. A walk that matched only T_STRING therefore missed
                // every backslash-prefixed write.
                //
                // ⭐ This is not theoretical: the mutation control
                // "a write hides behind a LEADING BACKSLASH" SURVIVED the
                // first version of this guard. The guard looked right, the
                // suite was green, and `\wp_trash_post()` walked straight
                // past it. Verified against token_get_all() afterwards.
                if (!is_array($t) || !in_array(
                    $t[0],
                    [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED],
                    true
                )) {
                    continue;
                }

                // `\wp_insert_post` → `wp_insert_post`. A QUALIFIED name
                // (`Foo\bar`) keeps its prefix and so matches nothing in
                // WRITERS, which is correct: that is a namespaced function,
                // not WordPress's global one.
                $name = ltrim($t[1], '\\');

                // a call needs a `(` next
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j >= $count || $tokens[$j] !== '(') {
                    continue;
                }

                if ($name === 'pinOnly') {
                    $pinStack[] = $depth + 1;
                    continue;
                }

                if (!isset(self::WRITERS[$name]) && !in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                // ⚠ `->wp_update_post(` or `Foo::wp_update_post(` is someone
                // else's method, not the global function. A LEADING
                // BACKSLASH is ours — `\wp_insert_post()` is the same
                // function, so T_NS_SEPARATOR is deliberately NOT skipped
                // here.
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && in_array(
                    $tokens[$prev][0],
                    [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION],
                    true
                )) {
                    continue;
                }

                // A dispatcher on its own writes nothing; the string branch
                // above already recorded it if it named a writer.
                if (in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                $rows[] = [
                    'site'   => $rel . ':' . $line,
                    'fn'     => $name,
                    'pinned' => $pinStack !== [],
                ];
            }
        }

        return $rows;
    }

    /**
     * ⛔ The guard itself: nothing unpinned, and nothing allowlisted.
     */
    public function testEveryPostWriteIsPinned(): void
    {
        $files = self::phpFiles();

        self::assertGreaterThanOrEqual(
            self::MIN_FILES,
            count($files),
            'only ' . count($files) . ' PHP files scanned — too few to be the real tree, so a'
            . ' clean result would mean nothing. Check the roots are checked out.'
        );

        $unpinned = [];
        foreach (self::callSites($files) as $row) {
            if ($row['pinned'] || in_array($row['site'], self::ALLOWLIST, true)) {
                continue;
            }
            $unpinned[] = $row['site'] . '  (' . $row['fn'] . ')';
        }

        self::assertSame(
            [],
            $unpinned,
            "These post writes are NOT inside PeepSoAddPostGuard::pinOnly():\n  "
            . implode("\n  ", $unpinned)
            . "\n\nEvery one of these reaches the `wp_insert_post` action, where three"
            . "\nPeepSo listeners read the CURRENT REQUEST with no authorization check"
            . "\nof their own (mood, location, anon_id). Wrap the write in pinOnly()."
            . "\nDo NOT add it to this test's allowlist — that is the failure mode this"
            . "\nguard exists to prevent."
        );
    }

    /**
     * ⚠ ANTI-VACUITY, and this is the assertion that makes the one above
     * worth having. A walk that silently matched nothing would also report
     * "no unpinned writes". So: the tree must contain post writes at all,
     * and at least one must be PINNED — which proves the pin detection
     * works rather than merely that the writer detection found nothing.
     */
    public function testTheWalkActuallyFindsWritesAndSeesPins(): void
    {
        $rows = self::callSites(self::phpFiles());

        self::assertNotEmpty($rows, 'the walk found NO post writes at all — it is not measuring anything');

        $pinned = array_filter($rows, static fn(array $r): bool => $r['pinned']);
        self::assertNotEmpty(
            $pinned,
            'the walk found writes but recognised NO pin — pin detection is broken,'
            . ' which would make the guard above vacuous'
        );
    }

    /** The allowlist is empty, and a test says so out loud. */
    public function testTheAllowlistIsEmpty(): void
    {
        self::assertSame(
            [],
            self::ALLOWLIST,
            'the allowlist must stay empty — see the constant\'s own comment'
        );
    }

    /**
     * ⚠ Each writer is in the list because WordPress's source says it
     * reaches the action. The citation is part of the entry so a reviewer
     * can check the claim instead of trusting the name.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function writerProvider(): array
    {
        $out = [];
        foreach (self::WRITERS as $fn => $why) {
            $out[] = [$fn, $why];
        }

        return $out;
    }

    #[DataProvider('writerProvider')]
    public function testEveryWriterCarriesItsEvidence(string $fn, string $why): void
    {
        self::assertNotSame('', $why, "{$fn} must cite why it reaches the action");
        self::assertMatchesRegularExpression(
            '/\b(post|media)\.php:\d+|calls \w+\(\)/',
            $why,
            "{$fn}'s evidence must name a source line or the function it calls"
        );
    }

    /**
     * ⚠⚠ THE GUARD'S OWN BLIND SPOT, made loud.
     *
     * {@see WRITERS} is this guard's INPUT, so the guard cannot validate
     * it: delete `wp_trash_post` from that list and every assertion above
     * still passes, while the guard quietly stops seeing a whole class of
     * write. That is not a hypothetical — a short writer list is exactly
     * how the first pass at F-361 missed `wp_update_post` and the comment
     * trash.
     *
     * This cannot be fixed by a test (checking the list against itself
     * proves nothing), so it is made EXPENSIVE instead: the set is pinned
     * here, and shrinking it means editing two places and explaining why
     * in review. A tripwire, not a proof — and it is written down as such
     * rather than dressed up.
     */
    public function testTheWriterSetIsPinnedAgainstSilentShrinking(): void
    {
        $expected = [
            'check_and_publish_future_post',
            'media_handle_sideload',
            'media_handle_upload',
            'media_sideload_image',
            'wp_insert_attachment',
            'wp_insert_post',
            'wp_publish_post',
            'wp_trash_post',
            'wp_untrash_post',
            'wp_update_post',
        ];

        $actual = array_keys(self::WRITERS);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'the writer set changed. ADDING one is welcome — cite the WordPress source'
            . ' line that proves it reaches the action, and update this list. REMOVING'
            . ' one makes this guard blind to that whole class of write, so it needs a'
            . ' reason in review, not a quiet edit.'
        );
    }
}
