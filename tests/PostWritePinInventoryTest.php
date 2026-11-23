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
 * F-361's first pass scoped itself to "writes reachable from a member's
 * request". That needs a call per site, and the call was wrong FIVE
 * times, each for a different reason:
 *
 *   wp_update_post in a review-reply publish  — not spelled wp_insert_post
 *   a comment TRASH                           — did not look like a write
 *   wp_insert_attachment (cover upload)        — not spelled wp_insert_post
 *   new PeepSoGroup(null, $data) ×3            — not a function call at all
 *   wp_save_post_revision ×2                   — a revision is a post
 *
 * ⚠⚠ The three provisioning services' own docblocks ALREADY said
 * "`new PeepSoGroup(null, $data)` runs `wp_insert_post()`". The codebase
 * knew; nothing connected that knowledge to a check. That is the whole
 * case for this test.
 *
 * "Every BCC post write is pinned" needs no judgment, so it can be
 * checked — and {@see ALLOWLIST} is EMPTY, because an allowlist naming
 * the sites a guard exists to catch is a green that means nothing.
 *
 * ── WHAT COUNTS AS A POST WRITE ─────────────────────────────────────
 *
 * Anything reaching the `wp_insert_post` ACTION, which third-party
 * listeners hook. The listener inventory itself is with the security review (private tracker)
 * — this repo is public. ⚠ Every entry in {@see WRITERS} was confirmed by
 * reading WordPress's source.
 *
 * ── THE THREE WAYS A NAIVE GUARD IS WRONG ───────────────────────────
 *
 * All three were found by mutation controls or a runnable case, not by
 * reading the code:
 *
 *   1. TEXT, NOT TOKENS. `substr_count` of
 *      `PeepSoAddPostGuard::pinOnly(` read 3 for 2 calls, because the
 *      comment explaining the guard named it.
 *   2. ONE NAME TOKEN, NOT THREE. PHP 8 lexes `\wp_trash_post(` as a
 *      single T_NAME_FULLY_QUALIFIED, so a T_STRING-only walk missed
 *      every backslash-prefixed write.
 *   3. PAREN DEPTH IS NOT A PIN. `pinOnly([], wp_insert_post([...]))`
 *      nests inside pinOnly's parens, but PHP evaluates an ARGUMENT
 *      before the call — so the write runs BEFORE the request is pinned
 *      while a depth walk calls it protected.
 */
final class PostWritePinInventoryTest extends TestCase
{
    /**
     * Functions whose call reaches the `wp_insert_post` action, each
     * citing the WordPress source that proves it.
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
        '_wp_put_post_revision'         => 'revision.php:19 — wp_insert_post($post, true)',
        'wp_restore_post_revision'      => 'revision.php:25 — calls wp_update_post()',
        // two hops
        'media_sideload_image'          => 'media.php:46 — media_handle_sideload()',
        'wp_save_post_revision'         => 'revision.php:99 — calls _wp_put_post_revision()',
        // conditional — see CONDITIONAL_WRITERS
        'wp_delete_post'                => 'post.php:18-22 — returns wp_trash_post() when NOT forced',
        'wp_delete_attachment'          => 'its :16 — returns wp_trash_post() when NOT forced',
    ];

    /**
     * ⚠ These reach the action only when NOT forced, under a condition a
     * token walk cannot evaluate: `wp_delete_post` needs post-or-page,
     * a non-trash status and `EMPTY_TRASH_DAYS`; `wp_delete_attachment`
     * needs `EMPTY_TRASH_DAYS` AND `MEDIA_TRASH`. So the rule is
     * structural: an explicit `true` second argument is exempt, anything
     * else counts. That can only OVER-flag, which is the safe direction,
     * and the condition is written down because it — not the name — is
     * the reason.
     *
     * @var list<string>
     */
    private const CONDITIONAL_WRITERS = ['wp_delete_post', 'wp_delete_attachment'];

    /**
     * ⛔ The ONLY receivers whose `->update(` is not a model write.
     * Deliberately one name. {@see updateIsNotOnWpdb()}.
     *
     * @var list<string>
     */
    private const WPDB_RECEIVERS = ['$wpdb'];

    /**
     * ⛔ The guard's FULLY QUALIFIED name. A pin is only armed by THIS
     * class — see {@see isRealGuardCall()} for why the short name alone is
     * not enough.
     */
    private const GUARD_NAMESPACE = 'BCC\\Core\\PeepSo';
    private const GUARD_FQN       = self::GUARD_NAMESPACE . '\\PeepSoAddPostGuard';

    /** Indirect dispatch: these take a callable NAME as a string. */
    private const DISPATCHERS = ['call_user_func', 'call_user_func_array'];

    /**
     * ⛔ DELIBERATELY EMPTY, and it must stay that way.
     *
     * Adding an entry here is the thing this test exists to prevent. If a
     * write genuinely cannot be pinned, that needs a decision and a
     * stated reason in review — not a quiet line in a test fixture.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    /** Directories this guard polices. bcc-trust's twin polices app/ + includes/. */
    private const ROOTS = ['src'];

    /**
     * Single files outside those roots that still ship code.
     *
     * ⚠ SCANNED rather than excluded, on security's point: a write in a
     * plugin bootstrap or an uninstall routine is still a write.
     */
    private const EXTRA_FILES = ['bcc-core.php', 'uninstall.php'];

    /**
     * ⛔ VACUITY FLOOR. A worktree without the tree makes every scan pass
     * over nothing — the false green that let the umbrella guards "pass"
     * in a checkout with no plugin trees. Fewer files than this FAILS.
     */
    private const MIN_FILES = 50;

    private static function baseDir(): string
    {
        return dirname(__DIR__);
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $base  = self::baseDir();
        $files = [];

        foreach (self::ROOTS as $dir) {
            $root = $base . '/' . $dir;
            self::assertDirectoryExists($root, "guarded root {$dir} is missing");

            // ⚠ SKIP_DOTS is load-bearing: without it the iterator yields
            // `.` and recurses into it forever. Measured — the first
            // version of this walk hung.
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        foreach (self::EXTRA_FILES as $rel) {
            $path = $base . '/' . $rel;
            self::assertFileExists($path, "guarded file {$rel} is missing");
            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Every post-write call site, with whether a `pinOnly(` is genuinely
     * open around it.
     *
     * @param list<string> $files
     * @return list<array{site: string, fn: string, pinned: bool, note: string}>
     */
    private static function callSites(array $files): array
    {
        $base = self::baseDir();
        $rows = [];

        foreach ($files as $file) {
            $src    = (string) file_get_contents($file);
            $tokens = token_get_all($src);
            $count  = count($tokens);
            $rel    = str_replace('\\', '/', substr($file, strlen($base) + 1));

            // ── pre-pass: which variables hold a PeepSoGroup? ──────────
            //
            // ⚠ FILE-scoped, not function-scoped, and that is the
            // conservative direction: it can only ever flag MORE
            // `->update(` calls, never fewer. Measured before choosing it —
            // trust `app/` has 69 `->update(` sites of which 68 are
            // `$wpdb->update(`, so binding to a receiver is what keeps this
            // rule from producing 68 false positives against one true one.
            // A guard that noisy gets switched off.

            // ── main walk ──────────────────────────────────────────────
            $depth    = 0;
            $pins      = [];   // list<array{depth:int}>  open pinOnly( calls
            $bodies    = [];   // list<array{kind:string, startDepth:int, brace:int}>
            $pendingFn = null; // closure seen, body not yet open
            $pendingAt = 0;
            $line     = 1;

            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];

                if (is_array($t)) {
                    $line = $t[2];

                    // ⚠⚠ HARDENING 1 — a write is pinned ONLY inside a
                    // closure opened within pinOnly's ARGUMENT LIST.
                    //
                    // `pinOnly([], wp_insert_post([...]))` is nested inside
                    // pinOnly's parens, so a paren-depth walk calls it
                    // pinned — but PHP evaluates an ARGUMENT before the call,
                    // so the write completes BEFORE the request is pinned.
                    // Proved with a runnable case, not reasoned about. A
                    // guard satisfiable by an unprotected write is worse than
                    // no guard, because it licenses the pattern.
                    // ⛔⛔ F-457 — ARMING IS PER CLOSURE BODY, NOT PER PIN.
                    // The per-pin version this replaces was satisfied by a
                    // DECOY closure sitting beside the write:
                    //
                    //   pinOnly([static fn () => null], new PeepSoGroup(null, $d))
                    //
                    // Measured on the real guard: that read pinned=YES while
                    // the write still ran first. bcc-trust's twin was fixed in
                    // #806; this is the port.
                    if (($t[0] === T_FUNCTION || $t[0] === T_FN) && $pins !== []
                        && $depth >= $pins[count($pins) - 1]['depth']) {
                        $pendingFn = $t[0] === T_FN ? 'fn' : 'block';
                        $pendingAt = $depth;
                    }

                    // An arrow fn body begins at `=>`.
                    if ($t[0] === T_DOUBLE_ARROW && $pendingFn === 'fn') {
                        $bodies[] = ['kind' => 'fn', 'startDepth' => $pendingAt, 'brace' => 0];
                        $pendingFn = null;
                    }

                    // HARDENING 3 — `use function` importing a writer. An
                    // ALIASED import (`use function wp_insert_post as x;`)
                    // would let the call be spelled `x(`, which no
                    // name-based walk can see. Reported rather than silently
                    // tolerated.
                    if ($t[0] === T_STRING || $t[0] === T_NAME_FULLY_QUALIFIED || $t[0] === T_NAME_QUALIFIED) {
                        if (isset(self::WRITERS[self::bare($t[1])]) && self::isInUseFunction($tokens, $i)) {
                            $rows[] = [
                                'site'   => $rel . ':' . $line,
                                'fn'     => self::bare($t[1]),
                                'pinned' => false,
                                'note'   => 'imported with `use function` — an alias would hide every call from this guard',
                            ];
                        }
                    }

                    // call_user_func('wp_insert_post', …): the writer's name
                    // is a STRING, not a T_STRING.
                    //
                    // ⚠⚠ ONLY INSIDE A DISPATCHER, and that qualifier is not
                    // pedantry — without it this flagged
                    // `function_exists('wp_save_post_revision')`, which is a
                    // GUARD rather than a call. Measured: the first version
                    // reported two false positives on exactly that line, in
                    // code that was already careful. A guard that flags
                    // defensive code teaches people to disable it.
                    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $literal = trim($t[1], "'\"\\");
                        if (isset(self::WRITERS[$literal])
                            && in_array(self::enclosingCallName($tokens, $i), self::DISPATCHERS, true)) {
                            $rows[] = [
                                'site'   => $rel . ':' . $line,
                                'fn'     => $literal . ' (as a string)',
                                'pinned' => self::inPinnedClosure($bodies),
                                'note'   => 'dispatched by name through call_user_func',
                            ];
                        }
                    }

                    // ── PeepSo MODEL write: `new PeepSoGroup($a, $b)` ──
                    //
                    // ⚠ TWO arguments CREATE (group.php:308 →
                    // wp_insert_post); ONE argument only LOADS. The three
                    // provisioning services each do both, so arg count is
                    // the whole distinction.
                    if ($t[0] === T_NEW) {
                        $j = self::nextSignificant($tokens, $i + 1, $count);
                        if ($j !== null && is_array($tokens[$j]) && self::bare($tokens[$j][1]) === 'PeepSoGroup') {
                            $open = self::nextSignificant($tokens, $j + 1, $count);
                            if ($open !== null && $tokens[$open] === '('
                                && self::topLevelArgs($tokens, $open, $count) >= 2) {
                                $rows[] = [
                                    'site'   => $rel . ':' . $line,
                                    'fn'     => 'new PeepSoGroup(…, …)',
                                    'pinned' => self::inPinnedClosure($bodies),
                                    'note'   => 'PeepSo model create — group.php:308 runs wp_insert_post()',
                                ];
                            }
                        }
                    }
                }

                // A block closure body runs from its `{` to the matching
                // `}`. An arrow fn body runs from `=>` to the end of that
                // argument — so it ends at a `,` at its own paren depth, or
                // when the depth drops below it.
                if ($t === '{') {
                    if ($pendingFn === 'block') {
                        $bodies[]  = ['kind' => 'block', 'startDepth' => $pendingAt, 'brace' => 1];
                        $pendingFn = null;
                    } elseif ($bodies !== [] && $bodies[count($bodies) - 1]['kind'] === 'block') {
                        $bodies[count($bodies) - 1]['brace']++;
                    }
                    continue;
                }
                if ($t === '}') {
                    if ($bodies !== [] && $bodies[count($bodies) - 1]['kind'] === 'block') {
                        $bodies[count($bodies) - 1]['brace']--;
                        if ($bodies[count($bodies) - 1]['brace'] <= 0) {
                            array_pop($bodies);
                        }
                    }
                    continue;
                }
                if ($t === ',') {
                    // An arrow-fn body ends with its argument.
                    while ($bodies !== []
                        && $bodies[count($bodies) - 1]['kind'] === 'fn'
                        && $bodies[count($bodies) - 1]['startDepth'] >= $depth) {
                        array_pop($bodies);
                    }
                    continue;
                }

                if ($t === '(') {
                    $depth++;
                    continue;
                }
                if ($t === ')') {
                    // Closing this paren ends any arrow-fn body that opened
                    // inside it, and any pin whose own arg list it closes.
                    while ($bodies !== []
                        && $bodies[count($bodies) - 1]['kind'] === 'fn'
                        && $bodies[count($bodies) - 1]['startDepth'] >= $depth) {
                        array_pop($bodies);
                    }
                    while ($pins !== [] && $pins[count($pins) - 1]['depth'] >= $depth) {
                        array_pop($pins);
                    }
                    $depth--;
                    continue;
                }

                // ⚠⚠ THREE NAME TOKENS, NOT ONE. In PHP 8 a fully-qualified
                // call is a SINGLE token: `\wp_trash_post(` lexes as one
                // T_NAME_FULLY_QUALIFIED whose text includes the backslash,
                // NOT T_NS_SEPARATOR + T_STRING as in PHP 7.
                //
                // ⭐ Found by a mutation control, not by reading: the mutant
                // "a write hides behind a LEADING BACKSLASH" SURVIVED the
                // first version of this guard, which looked correct and was
                // green.
                if (!is_array($t) || !in_array(
                    $t[0],
                    [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED],
                    true
                )) {
                    continue;
                }

                $name = self::bare($t[1]);

                // a call needs a `(` next
                $j = self::nextSignificant($tokens, $i + 1, $count);
                if ($j === null || $tokens[$j] !== '(') {
                    continue;
                }

                // ── PeepSo MODEL write: `$group->update([...])` ─────────
                if ($name === 'update' && self::updateIsNotOnWpdb($tokens, $i)) {
                    $rows[] = [
                        'site'   => $rel . ':' . $line,
                        'fn'     => '$group->update(…)',
                        'pinned' => self::inPinnedClosure($bodies),
                        'note'   => 'PeepSo model update — group.php:355 runs wp_update_post()',
                    ];
                    continue;
                }

                // ⚠⚠ HARDENING 2 — only the REAL guard arms a pin. Any
                // method named `pinOnly` would otherwise do, including one
                // a future class invents with different semantics.
                if ($name === 'pinOnly') {
                    if (self::isRealGuardCall($tokens, $i, $src)) {
                        $pins[] = ['depth' => $depth + 1];
                    }
                    continue;
                }

                if (!isset(self::WRITERS[$name]) && !in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                // `->wp_update_post(` or `Foo::wp_update_post(` is someone
                // else's method. A LEADING BACKSLASH is ours, which is why
                // T_NS_SEPARATOR is not in this list.
                $prev = self::prevSignificant($tokens, $i - 1);
                if ($prev !== null && is_array($tokens[$prev]) && in_array(
                    $tokens[$prev][0],
                    [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION],
                    true
                )) {
                    continue;
                }

                // A dispatcher writes nothing itself; its string argument was
                // recorded above.
                if (in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                // ⚠ `wp_delete_post` / `wp_delete_attachment` only reach the
                // action when NOT forced: each returns `wp_trash_post()`
                // under a condition a token walk cannot evaluate
                // (post type, EMPTY_TRASH_DAYS, MEDIA_TRASH, current
                // status). So the rule is structural rather than semantic:
                // an explicit `true` second argument is exempt, anything
                // else counts as a write. That is the safe reading — it can
                // only over-flag — and it is written down because the
                // condition is the reason, not the name.
                if (in_array($name, self::CONDITIONAL_WRITERS, true)
                    && self::hasExplicitTrueSecondArg($tokens, $j, $count)) {
                    continue;
                }

                $rows[] = [
                    'site'   => $rel . ':' . $line,
                    'fn'     => $name,
                    'pinned' => self::inPinnedClosure($bodies),
                    'note'   => self::WRITERS[$name],
                ];
            }
        }

        return $rows;
    }

    /** Is any enclosing pin ARMED — i.e. did a closure open inside it? */
    /** @param list<array{depth: int, armed: bool}> $pins */
    /**
     * Is the current token inside the BODY of a closure that is an argument
     * to `pinOnly(`?
     *
     * ⚠ Not "is a pin open" and not "did a closure appear in its
     * arguments" — both of those count an evaluated argument as pinned.
     * Only a write inside the closure body runs after the request has been
     * neutralised.
     *
     * @param list<array{kind: string, startDepth: int, brace: int}> $bodies
     */
    private static function inPinnedClosure(array $bodies): bool
    {
        return $bodies !== [];
    }

    /**
     * The name of the call whose argument list this token sits in, or ''.
     *
     * Walks back balancing parens to the `(` that opened the current
     * argument list, then reads the name before it. Used to tell
     * `call_user_func('wp_insert_post', …)` — a real dispatch — from
     * `function_exists('wp_save_post_revision')`, which is a guard.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function enclosingCallName(array $tokens, int $i): string
    {
        $depth = 0;

        for ($k = $i - 1; $k >= 0; $k--) {
            $t = $tokens[$k];

            if ($t === ')' || $t === ']') {
                $depth++;
                continue;
            }
            if ($t === '(' || $t === '[') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                // this `(` opens our argument list
                $n = self::prevSignificant($tokens, $k - 1);
                if ($n !== null && is_array($tokens[$n]) && in_array(
                    $tokens[$n][0],
                    [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED],
                    true
                )) {
                    return self::bare($tokens[$n][1]);
                }

                return '';
            }
            if ($t === ';' || $t === '{' || $t === '}') {
                return '';
            }
        }

        return '';
    }

    /** `\Foo\Bar` and `Bar` both reduce to the trailing name. */
    private static function bare(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $from, int $count): ?int
    {
        for ($i = $from; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array(
                $tokens[$i][0],
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                true
            )) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array(
                $tokens[$i][0],
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                true
            )) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * ⚠⚠ HARDENING 2. A pin counts only when it is
     * `PeepSoAddPostGuard::pinOnly(` — or `self::`/`static::` INSIDE that
     * class, which only bcc-core's own file can be.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isRealGuardCall(array $tokens, int $i, string $src): bool
    {
        $colon = self::prevSignificant($tokens, $i - 1);
        if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
            return false;
        }

        $cls = self::prevSignificant($tokens, $colon - 1);
        if ($cls === null || !is_array($tokens[$cls])) {
            return false;
        }

        $written = ltrim($tokens[$cls][1], '\\');

        // ⛔⛔ F-457 — THE FULLY QUALIFIED NAME, or the short name only
        // with evidence that it resolves to it in THIS file. The old rule
        // matched the bare short name anywhere, so any class called
        // `PeepSoAddPostGuard` in any namespace — or a local stub of that
        // name — armed a pin it has nothing to do with.
        if ($written === self::GUARD_FQN) {
            return true;
        }

        if (self::bare($written) === 'PeepSoAddPostGuard') {
            // The short name is the real guard when the file declares the
            // guard's own namespace, or imports exactly that FQN.
            // ⚠⚠ TOKENS, NOT `str_contains`. A COMMENT naming the import —
            // `// use BCC\\Core\\PeepSo\\PeepSoAddPostGuard;` — satisfied the
            // text check and armed every pin in the file. That is the same
            // trap this guard documents elsewhere (a comment naming a
            // mechanism making the mechanism react), and it had reappeared
            // here, in the rule written to CLOSE a name-matching hole.
            return self::declaresGuardNamespace($tokens) || self::importsGuardFqn($tokens);
        }

        // self:: / static:: only inside the guard class itself.
        return in_array(strtolower($written), ['self', 'static'], true)
            && self::declaresGuardClass($tokens);
    }

    /**
     * Does this file DECLARE the guard's own namespace?
     *
     * ⚠ Token-based for the reason given at the call site: a comment naming
     * the namespace must not count.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaresGuardNamespace(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }
            $j = self::nextSignificant($tokens, $i + 1, $count);
            if ($j !== null && is_array($tokens[$j])
                && ltrim($tokens[$j][1], '\\') === self::GUARD_NAMESPACE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this file IMPORT the guard by its fully qualified name?
     *
     * ⚠ A `use … as Something;` alias is deliberately NOT accepted: the
     * alias would then arm pins under a name this walk cannot follow, which
     * is the hole the forbid rules exist for.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function importsGuardFqn(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }
            $j = self::nextSignificant($tokens, $i + 1, $count);
            if ($j === null || !is_array($tokens[$j])
                || ltrim($tokens[$j][1], '\\') !== self::GUARD_FQN) {
                continue;
            }
            // A plain import ends at `;`. `use X as Y;` does not count.
            $k = self::nextSignificant($tokens, $j + 1, $count);
            if ($k !== null && $tokens[$k] === ';') {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the guard class DECLARED in this file? (Gates `self::` / `static::`.)
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaresGuardClass(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) {
                continue;
            }
            $j = self::nextSignificant($tokens, $i + 1, $count);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][1] === 'PeepSoAddPostGuard') {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this name token the tail of a `use function …;` statement?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isInUseFunction(array $tokens, int $i): bool
    {
        $seenFunction = false;
        for ($k = $i - 1; $k >= 0 && $k > $i - 12; $k--) {
            if (!is_array($tokens[$k])) {
                if ($tokens[$k] === ';' || $tokens[$k] === '{') {
                    return false;
                }
                continue;
            }
            if ($tokens[$k][0] === T_FUNCTION) {
                $seenFunction = true;
                continue;
            }
            if ($tokens[$k][0] === T_USE) {
                return $seenFunction;
            }
        }

        return false;
    }

    /**
     * Count TOP-LEVEL arguments in the call whose `(` sits at $open.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function topLevelArgs(array $tokens, int $open, int $count): int
    {
        $depth = 0;
        $args  = 0;
        $any   = false;

        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(' || $t === '[') {
                $depth++;
                continue;
            }
            if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    return $any ? $args + 1 : 0;
                }
                continue;
            }
            if ($depth === 1) {
                if ($t === ',') {
                    $args++;
                    continue;
                }
                if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $any = true;
                }
            }
        }

        return $any ? $args + 1 : 0;
    }

    /**
     * Was the receiver of this `->update(` assigned from a PeepSoGroup?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * ⛔⛔ F-457 — DENY BY DEFAULT. This used to ALLOW every `->update(`
     * except one whose receiver a pre-pass had tied to a literal
     * `new PeepSoGroup`. Every way of getting a group object that the
     * pre-pass could not see — an alias, a subclass, a dynamic `new`, a
     * receiver assigned from a method or a property — therefore walked
     * straight past it.
     *
     * Now the question is inverted: an `->update(` is a model write unless
     * its receiver is provably `$wpdb`. ⚠ Measured before adopting it —
     * core `src/` holds ONE `->update(` call (plus one in a comment, which
     * this walk skips), and it is the group write, already pinned; bcc-trust
     * `app/` holds 83, every one of them `$wpdb->update(`. So the exclusion
     * is the whole cost of the inversion.
     *
     * ⚠ THE LIMIT, stated rather than hidden: the exclusion is itself
     * NAME-based. `$db = $wpdb; $db->update(...)` is flagged (a false
     * positive, which fails safe), and a model update written through a
     * variable that happens to be called `$wpdb` is missed. It is one
     * receiver name rather than an open-ended allowlist, and what this
     * guard defends against is a PeepSo MODEL write, not a wpdb write.
     */
    private static function updateIsNotOnWpdb(array $tokens, int $i): bool
    {
        // ⚠⚠ BOTH OBJECT OPERATORS. PHP 8's nullsafe `?->` is its own token,
        // T_NULLSAFE_OBJECT_OPERATOR, so treating only T_OBJECT_OPERATOR let
        // `$group?->update([...])` past the rule entirely — the same shape of
        // miss as PHP 8 lexing `\wp_trash_post(` as ONE name token.
        $arrow = self::prevSignificant($tokens, $i - 1);
        if ($arrow === null || !is_array($tokens[$arrow])
            || !in_array($tokens[$arrow][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return false;
        }

        $recv = self::prevSignificant($tokens, $arrow - 1);
        if ($recv === null) {
            return false;
        }

        // ⚠⚠ A CALL RESULT IS OUT OF SCOPE, A PROPERTY IS NOT, and keeping
        // those apart is the whole subtlety here.
        //
        // Denying every receiver that is not a plain `$var` was the first
        // attempt, and it was WRONG IN BOTH DIRECTIONS. It flagged
        // `Plugin::instance()->reviewReplyService()->update(...)` in bcc-trust
        // — an ordinary service call — and, worse, it stopped flagging
        // `$this->group->update(...)`, because a PROPERTY receiver is a
        // T_STRING after `->`, not a T_VARIABLE. That property form is the
        // likeliest real dodge of the three, so the first narrowing quietly
        // gave up the case it mattered most to keep. A fixture caught it.
        // Anything that is not a NAME token — the `)` of a call, the `]` of
        // an index — is out of scope, and the gap is NAMED: a model update
        // reached through a chained expression is not caught. Ordinary
        // refactoring produces `$group->update(` or `$this->group->update(`,
        // both of which ARE caught below.
        //
        // ⚠ There is deliberately no separate `=== ')'` branch: it would be
        // unreachable past this check, and a mutation control proved it —
        // removing that branch changed no verdict. A rule that cannot change
        // an outcome is documentation pretending to be code.
        if (!is_array($tokens[$recv])) {
            return false;
        }

        // `$group->update(` — a plain variable.
        if ($tokens[$recv][0] === T_VARIABLE) {
            return !in_array($tokens[$recv][1], self::WPDB_RECEIVERS, true);
        }

        // `$this->group->update(` — a property. `$this->wpdb->update(` is
        // excluded on the same name, so the exclusion covers both spellings.
        if ($tokens[$recv][0] === T_STRING) {
            return !in_array('$' . $tokens[$recv][1], self::WPDB_RECEIVERS, true);
        }

        return false;
    }

    /**
     * Does this call pass a literal `true` as its SECOND argument?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    /**
     * The forbidden shapes, as "kind file:line" strings.
     *
     * ⚠ TOKEN-BASED, not text. A comment that NAMES `class_alias(` or shows
     * an aliased import as an example must not trip the scan — the same trap
     * that made a `substr_count` of the guard's own name read 3 for 2 calls.
     * `token_get_all()` returns comments as single tokens, so the walk cannot
     * see inside them.
     *
     * @param  list<string> $files
     * @return list<string>
     */
    private static function dodges(array $files): array
    {
        $found = [];

        foreach ($files as $path) {
            $src    = (string) file_get_contents($path);
            $tokens = token_get_all($src);
            $count  = count($tokens);
            $rel    = str_replace(self::baseDir() . DIRECTORY_SEPARATOR, '', $path);
            $rel    = str_replace('\\', '/', $rel);

            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];
                if (!is_array($t)) {
                    continue;
                }
                $line = $t[2];
                $add  = static function (string $kind) use (&$found, $rel, $line): void {
                    $found[] = $kind . ' ' . $rel . ':' . $line;
                };

                // ⚠⚠ A DYNAMIC `new $var(` IS NOT FORBIDDEN HERE, and that is a
                // MEASURED decision rather than an oversight. A class name in a
                // variable cannot be resolved by a token walk, so forbidding it
                // was the first instinct — but scanning the tree for it flags
                // `ServiceLocator`'s null-object factory (`new $nullClass()`),
                // where dynamic construction is the file's whole purpose, and it
                // would flag every future factory the same way.
                //
                // Excluding that by PATH would make this an allowlist, which is
                // what the rest of this guard exists to avoid. So the gap is
                // NAMED instead: a model write whose class name is deliberately
                // indirected is not caught. The aliased-import and subclass rules
                // below cover the paths that arise from ordinary refactoring; a
                // variable class name is a deliberate act, and a deliberate
                // bypass is not what a mechanical guard can stop.

                // `class_alias(` as a CALL, not as a name in a string.
                if ($t[0] === T_STRING && self::bare($t[1]) === 'class_alias') {
                    $j = self::nextSignificant($tokens, $i + 1, $count);
                    if ($j !== null && $tokens[$j] === '(') {
                        $add('class_alias');
                    }
                    continue;
                }

                // `use …PeepSo… as X;` — an import that renames a PeepSo class.
                if ($t[0] === T_AS) {
                    $p = self::prevSignificant($tokens, $i - 1);
                    if ($p !== null && is_array($tokens[$p]) && str_contains($tokens[$p][1], 'PeepSo')) {
                        $add('aliased-import');
                    }
                    continue;
                }

                // `extends …PeepSo…` — a subclass of a PeepSo model.
                if ($t[0] === T_EXTENDS) {
                    $j = self::nextSignificant($tokens, $i + 1, $count);
                    if ($j !== null && is_array($tokens[$j]) && str_contains($tokens[$j][1], 'PeepSo')) {
                        $add('extends-model');
                    }
                    continue;
                }

                // `[$obj, 'update']` — the method name as data, never as a call.
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], "'\"") === 'update') {
                    if (self::isUpdateCallablePair($tokens, $i)) {
                        $add('update-callable');
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Is this `'update'` literal the second element of an `[$obj, 'update']`
     * callable pair, and NOT a REST route's `'callback' =>` value?
     *
     * ⚠ The exclusion is the POSITION, not a file. bcc-trust registers one
     * such callback (`ReviewRepliesEndpoint`), where `update` is the
     * endpoint's own handler. Naming the file would make this an allowlist.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isUpdateCallablePair(array $tokens, int $i): bool
    {
        $comma = self::prevSignificant($tokens, $i - 1);
        if ($comma === null || $tokens[$comma] !== ',') {
            return false;
        }

        // Walk back to the `[` that opens this pair.
        $open = null;
        for ($j = $comma - 1, $depth = 0; $j >= 0; $j--) {
            if ($tokens[$j] === ']') {
                $depth++;
                continue;
            }
            if ($tokens[$j] === '[') {
                if ($depth === 0) {
                    $open = $j;
                    break;
                }
                $depth--;
            }
        }
        if ($open === null) {
            return false;
        }

        // A REST `'callback' => [...]` value is the endpoint's own handler.
        $arrow = self::prevSignificant($tokens, $open - 1);
        if ($arrow !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
            $key = self::prevSignificant($tokens, $arrow - 1);
            if ($key !== null && is_array($tokens[$key])
                && $tokens[$key][0] === T_CONSTANT_ENCAPSED_STRING
                && trim($tokens[$key][1], "'\"") === 'callback') {
                return false;
            }
        }

        return true;
    }

    private static function hasExplicitTrueSecondArg(array $tokens, int $open, int $count): bool
    {
        $depth  = 0;
        $argIdx = 0;

        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(' || $t === '[') {
                $depth++;
                continue;
            }
            if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            if ($t === ',') {
                $argIdx++;
                continue;
            }
            if ($argIdx === 1 && is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'true') {
                return true;
            }
        }

        return false;
    }

    // ── the assertions ───────────────────────────────────────────────

    /** ⛔ The guard: nothing unpinned, and nothing allowlisted. */
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
            $unpinned[] = $row['site'] . '  (' . $row['fn'] . ' — ' . $row['note'] . ')';
        }

        self::assertSame(
            [],
            $unpinned,
            "These post writes are NOT inside PeepSoAddPostGuard::pinOnly():\n  "
            . implode("\n  ", $unpinned)
            . "\n\nEach reaches the `wp_insert_post` action, where three PeepSo listeners"
            . "\nread the CURRENT REQUEST with no authorization check of their own"
            . "\n(mood, location, anon_id). Wrap the write in pinOnly(), with the write"
            . "\nINSIDE an arrow fn in pinOnly's argument list — a write passed as a bare"
            . "\nargument is evaluated BEFORE the pin and is not protected."
            . "\n\nDo NOT add it to this test's allowlist: that is the failure mode this"
            . "\nguard exists to prevent."
        );
    }

    /**
     * ⚠ ANTI-VACUITY, and this is what makes the assertion above worth
     * having. A walk that silently matched nothing would also report "no
     * unpinned writes". So the tree must contain writes, and at least one
     * must be recognised as PINNED — proving pin detection works rather
     * than that writer detection found nothing.
     */
    public function testTheWalkFindsWritesAndSeesPins(): void
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


    /**
     * ⚠⚠ COMMENTS ARE NOT CALL SITES — asserted, not assumed.
     *
     * This is the single most load-bearing property of the walk, because
     * THREE separate incidents on F-361 had exactly this shape:
     *
     *   - `substr_count('PeepSoAddPostGuard::pinOnly(')` read 3 for 2
     *     calls, because the comment EXPLAINING the guard named it;
     *   - the guard flagged `function_exists('wp_save_post_revision')`,
     *     a defensive check, because the name appeared as a string;
     *   - a comment saying "not a PHPStan suppression comment" BECAME
     *     one, because that tool reads comments too.
     *
     * ⭐ And it is not hypothetical here: `pinOnly()`'s own docblock now
     * carries the WRONG form — `pinOnly([], wp_insert_post(...))` — as a
     * worked example of what NOT to write, and in bcc-core that file is
     * inside the scanned tree. A text-matching guard would report its own
     * documentation as an unpinned write, and the obvious "fix" would be
     * to delete the example that stops the next author making the
     * mistake.
     *
     * `token_get_all()` returns each comment as ONE T_COMMENT /
     * T_DOC_COMMENT token, so a token walk cannot see inside it.
     */
    public function testACommentMentionIsNotACallSite(): void
    {
        $fixture = __DIR__ . '/fixtures/comment-only-writes.php.txt';
        self::assertFileExists($fixture, 'the comment-only fixture is missing');

        // Sanity: the fixture really does NAME writers, so a pass cannot
        // come from an empty file.
        $text = (string) file_get_contents($fixture);
        foreach (['wp_insert_post', 'wp_update_post', 'wp_trash_post', 'PeepSoGroup', 'call_user_func'] as $needle) {
            self::assertStringContainsString($needle, $text, "the fixture must mention {$needle}");
        }

        self::assertSame(
            [],
            self::callSites([$fixture]),
            'a writer named only in a COMMENT must not be reported as a call site —'
            . ' see this test\'s docblock for the three times that went wrong'
        );
    }

    /**
     * ⛔⛔ F-457 — THE CONSTRUCTS THAT WOULD WALK PAST THIS GUARD ARE
     * FORBIDDEN OUTRIGHT, rather than chased.
     *
     * Every rule above matches a model write BY NAME, so anything that
     * changes the name at the call site dodges it: an aliased import, a
     * subclass, `class_alias()`, or an `[$obj, 'update']` pair where the
     * method name never appears as a call at all.
     *
     * ⚠ A dynamic `new $var(` is deliberately NOT in this set — see
     * {@see dodges()} for the measurement that decided it.
     *
     * ⚠ Resolving these would mean following aliases and inheritance
     * through a token walk, which is where a walk stops being trustworthy.
     * Forbidding them is cheap and honest instead: it costs NOTHING today —
     * measured, every shape in this set is ZERO in bcc-core `src/` and, bar
     * the one structural exception below, zero in bcc-trust `app/` — and it
     * turns
     * "a future change could dodge the guard" into "a future change fails
     * this test and has to say why".
     *
     * ⚠ THE ONE EXCEPTION, and it is structural rather than a path
     * allowlist: a `[$obj, 'update']` pair that is a REST route's
     * `'callback' =>` value. bcc-trust registers exactly one
     * (`ReviewRepliesEndpoint`), where `update` is the endpoint's own
     * handler and nothing to do with a PeepSo model. Excluding the
     * `'callback' =>` POSITION keeps the rule meaningful; naming the file
     * would have made it an allowlist, which is the thing this guard is
     * built to avoid.
     */
    public function testTheConstructsThatWouldDodgeThisGuardAreForbidden(): void
    {
        $files = self::phpFiles();
        self::assertGreaterThanOrEqual(self::MIN_FILES, count($files), 'too few files to mean anything');

        self::assertSame(
            [],
            self::dodges($files),
            "These constructs can carry a PeepSo model write past every rule in this
"
            . "guard, because every rule matches a NAME at the call site:
"
            . "  an aliased import, a subclass, class_alias(), or an
"
            . "  [\$obj, 'update'] pair.

"
            . "None of them existed when this test was written, so this is not a
"
            . "cleanup task — it is a new construct that needs a decision. Either spell
"
            . "the write out so the guard can see it, or say in review why the guard
"
            . "should stop being able to."
        );
    }

    /**
     * ⚠ ANTI-VACUITY, and here it is doing real work. The tree contains NONE
     * of these shapes, so the assertion above passes over nothing — exactly
     * the false green this guard was built to refuse elsewhere. The fixture
     * contains one of each, and every one must be found.
     *
     * ⭐ It also pins WHICH shapes are covered: a kind that stops being
     * detected fails here rather than going quietly missing from the scan.
     */
    public function testTheDodgeScanFindsEveryShapeInTheFixture(): void
    {
        $found = self::dodges([__DIR__ . '/fixtures/dodged-writes.php.txt']);

        $kinds = [];
        foreach ($found as $row) {
            $kinds[substr($row, 0, (int) strpos($row, ' '))] = true;
        }
        ksort($kinds);

        self::assertSame(
            ['aliased-import', 'class_alias', 'extends-model', 'update-callable'],
            array_keys($kinds),
            'every forbidden shape must be detected in the fixture'
        );
    }

    /**
     * ⛔⛔ THE DECOY: a closure in the argument list does NOT pin a write
     * that sits beside it.
     *
     * The hole the first version of this hardening left, and subtle enough
     * to be worth spelling out. Arming used to be tracked per PIN, so:
     *
     *     pinOnly([static fn () => null], new PeepSoGroup(null, $data))
     *
     * armed the pin with a decoy closure in the FIRST argument, and the write
     * in the second was then reported `pinned=YES` — while still being an
     * evaluated argument that runs BEFORE the pin. Measured on the real
     * guard, not reasoned about.
     *
     * Arming is therefore tracked per closure BODY: a block closure from its
     * `{` to the matching `}`, an arrow fn from `=>` to the end of its
     * argument. Every write in this fixture is OUTSIDE any such body, so
     * every one must come back unpinned.
     *
     * ⭐ bcc-trust's twin guard was fixed in #806. This is the port, and it
     * is why the two guards now agree.
     */
    public function testADecoyClosureDoesNotPinAWriteBesideIt(): void
    {
        $fixture = __DIR__ . '/fixtures/decoy-closure-writes.php.txt';
        self::assertFileExists($fixture);

        $rows = self::callSites([$fixture]);
        self::assertNotEmpty($rows, 'the decoy fixture must contain writes, or this proves nothing');

        $wronglyPinned = [];
        foreach ($rows as $row) {
            if ($row['pinned']) {
                $wronglyPinned[] = $row['site'] . ' (' . $row['fn'] . ')';
            }
        }

        self::assertSame(
            [],
            $wronglyPinned,
            "These writes are NOT inside a closure body, so they run BEFORE the pin:\n  "
            . implode("\n  ", $wronglyPinned)
            . "\nbut the guard reported them as pinned. That is the decoy hole."
        );
    }

    /**
     * ⛔ A VANISHING TEST MUST FAIL CI, not be noticed by someone counting.
     *
     * ⚠ This exists because it HAPPENED on the bcc-trust twin: regenerating
     * that file dropped three cases — including the comment-mention check and
     * the decoy check above — and the suite stayed GREEN. A deleted test is
     * the one defect a passing run cannot show you, and every other assertion
     * in this class is only worth what its continued existence is worth.
     *
     * ⭐ The SET is pinned, not the count: that names what disappeared and
     * catches a silent RENAME too, which a `>= N` floor would not.
     *
     * ⚠ HONEST LIMIT, stated rather than hidden: this test cannot police its
     * own deletion. What it buys is that losing any OTHER case fails loudly,
     * so a quiet loss now takes two deliberate edits rather than one slip.
     */
    public function testEveryNamedCaseStillExists(): void
    {
        $expected = [
            'testAClassSharingTheGuardsShortNameDoesNotArmAPin',
            'testACommentMentionIsNotACallSite',
            'testADecoyClosureDoesNotPinAWriteBesideIt',
            'testAnUpdateOnANonWpdbReceiverIsAModelWrite',
            'testEveryNamedCaseStillExists',
            'testEveryPostWriteIsPinned',
            'testEveryWriterCarriesItsEvidence',
            'testTheAllowlistIsEmpty',
            'testTheConstructsThatWouldDodgeThisGuardAreForbidden',
            'testTheDodgeScanFindsEveryShapeInTheFixture',
            'testTheGroupConstructorFileSetIsReviewed',
            'testTheWalkFindsWritesAndSeesPins',
            'testTheWriterSetIsPinnedAgainstSilentShrinking',
        ];

        $actual = [];
        foreach ((new \ReflectionClass(self::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (str_starts_with($m->getName(), 'test')) {
                $actual[] = $m->getName();
            }
        }
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'a test case in this guard was added, removed or renamed. REMOVING one'
            . ' silently weakens the guard — the suite stays green while the check is'
            . ' gone, which is exactly how the bcc-trust twin lost three cases once'
            . ' already. Adding one is welcome: list it here too.'
        );
    }

    /**
     * ⛔⛔ F-457 — AN `->update(` IS A MODEL WRITE UNLESS ITS RECEIVER IS
     * PROVABLY `$wpdb`.
     *
     * The rule this replaces ALLOWED every `->update(` except one whose
     * receiver a file-scoped pre-pass had tied to a literal
     * `new PeepSoGroup`. So every other way of holding a group object walked
     * past it: a property, a method return, an alias, a subclass.
     *
     * ⚠ THIS TEST EXISTS BECAUSE A MUTATION CONTROL CAUGHT ITS ABSENCE.
     * Reverting the rule to allow-by-default left the whole suite GREEN — the
     * fixture contained the dodges, but nothing asserted that the WALK
     * reported them. A rule no test exercises is a rule that is not there.
     */
    public function testAnUpdateOnANonWpdbReceiverIsAModelWrite(): void
    {
        $rows = self::callSites([__DIR__ . '/fixtures/dodged-writes.php.txt']);

        $updates = [];
        foreach ($rows as $row) {
            if (str_contains($row['fn'], 'update')) {
                $updates[] = $row['site'];
            }
        }

        self::assertCount(
            3,
            $updates,
            'the property receiver, the method-return receiver and the NULLSAFE'
            . ' receiver must all be reported, and neither $wpdb form may be'
            . " reported, and \$wpdb->update( must NOT be. Got:\n  "
            . implode("\n  ", $updates)
        );

        foreach ($rows as $row) {
            if (str_contains($row['fn'], 'update')) {
                self::assertFalse($row['pinned'], $row['site'] . ' is not inside a pin');
            }
        }
    }

    /**
     * ⛔⛔ F-457 — ONLY THE REAL GUARD ARMS A PIN.
     *
     * `isRealGuardCall()` used to match the bare short name anywhere, so any
     * class called `PeepSoAddPostGuard` — in any namespace, including a local
     * stub or a test double — armed a pin it has nothing to do with. The
     * fixture declares exactly that: same short name, different namespace, no
     * import of the real FQN, and a write inside its `pinOnly()`.
     *
     * ⚠ Also caught by a mutation control rather than by inspection:
     * restoring the bare-name match left the suite green.
     */
    public function testAClassSharingTheGuardsShortNameDoesNotArmAPin(): void
    {
        $rows = self::callSites([__DIR__ . '/fixtures/impostor-guard.php.txt']);

        self::assertNotEmpty($rows, 'the fixture must contain a write, or this proves nothing');

        foreach ($rows as $row) {
            self::assertFalse(
                $row['pinned'],
                $row['site'] . ': a pin spelled by a class that merely shares the'
                . " guard's short name must not count as protection. The fixture"
                . ' also carries a COMMENT naming the real import, which a'
                . ' text-matching rule accepted as evidence.'
            );
        }

        // ⚠ EXACTLY ONE. The fixture also calls two METHODS named like
        // writers, one of them through `?->`. Those are method calls, not the
        // global functions, so a skip-list that does not know the nullsafe
        // operator reports them — a false positive, and the opposite failure
        // from the nullsafe miss in {@see updateIsNotOnWpdb()}.
        self::assertCount(
            1,
            $rows,
            'only the real write may be reported; a method that merely shares a'
            . " writer's name is not a call to that writer, through `->` or `?->`"
        );
    }

    /** The allowlist is empty, and a test says so out loud. */
    public function testTheAllowlistIsEmpty(): void
    {
        self::assertSame([], self::ALLOWLIST, 'the allowlist must stay empty — see the constant\'s comment');
    }

    /**
     * ⚠⚠ THE GUARD'S OWN BLIND SPOT, made expensive.
     *
     * {@see WRITERS} is this guard's INPUT, so the guard cannot validate
     * it: delete an entry and every assertion still passes while the
     * guard goes blind to that whole class of write. That is not
     * hypothetical — a short writer list is exactly how the first pass
     * missed five sites.
     *
     * It cannot be fixed by a test (checking the list against itself
     * proves nothing), so the set is pinned here instead: shrinking it
     * means editing two places and saying why in review. A TRIPWIRE, not
     * a proof, and it is labelled as one rather than dressed up.
     */
    public function testTheWriterSetIsPinnedAgainstSilentShrinking(): void
    {
        $expected = [
            '_wp_put_post_revision',
            'check_and_publish_future_post',
            'media_handle_sideload',
            'media_handle_upload',
            'media_sideload_image',
            'wp_delete_attachment',
            'wp_delete_post',
            'wp_insert_attachment',
            'wp_insert_post',
            'wp_publish_post',
            'wp_restore_post_revision',
            'wp_save_post_revision',
            'wp_trash_post',
            'wp_untrash_post',
            'wp_update_post',
        ];

        $actual = array_keys(self::WRITERS);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'the writer set changed. ADDING one is welcome — cite the WordPress source line'
            . ' that proves it reaches the action. REMOVING one makes this guard blind to a'
            . ' whole class of write, so it needs a reason in review, not a quiet edit.'
        );
    }

    /**
     * ⚠ THE PEEPSO-MODEL BACKSTOP, scoped so it stays maintainable.
     *
     * `->update(` cannot be flagged on its own: trust `app/` has 69
     * `->update(` call sites and 68 are `$wpdb->update(`. Measured, and
     * it is why this guard binds the method to a receiver assigned from
     * `new PeepSoGroup(` instead.
     *
     * The receiver binding is per FILE, so a group constructor appearing
     * in a NEW file is the case it could mis-scope. This assertion makes
     * that force a look: the set of files containing a 2-argument
     * `new PeepSoGroup(` is pinned to a reviewed list.
     *
     * ⛔ Scoped to CONSTRUCTOR files on purpose. ~60 files reference
     * `PeepSoGroup` at all, nearly all of them reads, and a 60-entry
     * fixture is one nobody maintains.
     */
    public function testTheGroupConstructorFileSetIsReviewed(): void
    {
        $base  = self::baseDir();
        $found = [];

        foreach (self::callSites(self::phpFiles()) as $row) {
            if ($row['fn'] === 'new PeepSoGroup(…, …)') {
                $found[] = explode(':', $row['site'])[0];
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        self::assertSame(
            ['src/PeepSo/PeepSoGroupWriter.php'],
            $found,
            'a 2-argument `new PeepSoGroup(` appeared in a file this list does not name'
            . ' (or left one). That constructor RUNS wp_insert_post (group.php:308), and the'
            . ' receiver binding for `->update(` is per file, so a new file needs a look'
            . ' rather than a silent pass.'
        );

        foreach ($found as $rel) {
            self::assertFileExists($base . '/' . $rel);
        }
    }

    /**
     * ⚠ Each writer is listed because WordPress's source says it reaches
     * the action. The citation is part of the entry so a reviewer can
     * check the claim instead of trusting the name.
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
            '/\b(post|media|revision)\.php:[\d-]+|its :\d+|calls \w+\(\)/',
            $why,
            "{$fn}'s evidence must name a source line or the function it calls"
        );
    }
}
