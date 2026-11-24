<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Cron\AsyncDispatcher;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\Repositories\PeepSoActivityRepository;

/**
 * F-542 — the post {@see PeepSoAddPostGuard::failClosedIfHijacked()} tried to
 * destroy may still be there, and nothing used to notice.
 *
 * ── WHAT GOES WRONG WITHOUT THIS ────────────────────────────────────
 *
 * `failClosedIfHijacked()` destroys a post it cannot vouch for, through
 * `PeepSoActivity::delete_post()`, and DISCARDS the result. It is reached in
 * the branch where a read has already failed, so the destroy can fail for the
 * same reason. The writer then returns `ok => false` and never reaches
 * `attachToGroup()`, so a surviving post carries NO group stamp at all and
 * reads as an ordinary post on the member's own wall.
 *
 * ── BEST-EFFORT REPAIR, AND WHERE THE GUARANTEE ACTUALLY IS ─────────
 *
 * ⚠⚠ The trash below is a BEST-EFFORT REPAIR, not a guarantee, and saying so
 * matters because the obvious reading is wrong. It takes no read of OURS — it
 * does not ask whether the post survived, it just writes — but
 * `wp_update_post()` performs its own `get_post()` (wp-includes/post.php),
 * and a FAILED `get_post()` is indistinguishable from "the post is gone": it
 * returns 0 before touching a row. In the very scenario that brings us here,
 * that read can fail too, and then this repair silently does nothing.
 *
 * ⇒ So {@see retry()} IS THE GUARANTEE. The bounded retry re-checks and
 * re-trashes until the post is gone or confirmed trashed, and records a
 * distinct metric if it gives up. Treating the cron as belt-and-braces would
 * mean having no guarantee at all.
 *
 * ── TWO DELIBERATE CHOICES ──────────────────────────────────────────
 *
 * ⭐ `wp_update_post(['post_status' => 'trash'])`, NOT `wp_trash_post()`.
 * PeepSo registers a `wp_trash_post` listener that reads post-meta and, when
 * it is set, writes to a DIFFERENT post (peepso/peepso.php:2863). That action
 * does not fire for a status write, so the cascade cannot be reached. Calling
 * `wp_trash_post()` would hand a third-party cascading write a post-meta read
 * in exactly the scenario where meta reads are failing.
 *
 * ⭐ The trash is PINNED. `save_post` still fires, and PeepSo's
 * `peepso/classes/blogposts.php:26` listener has NO post-type guard — it runs
 * on this write and can itself call `wp_update_post()` on a linked post. The
 * request is neutralised so nothing it reads can have come from the caller.
 *
 * Every other listener on `save_post` / `transition_post_status` was read and
 * returns early for this post type; the inventory is with the security review.
 *
 * @package BCC\Core\PeepSo
 */
final class OrphanedPostRepair
{
    /** The retry hook. Args are a zero-indexed list: [$postId, $attempt]. */
    public const RETRY_HOOK = 'bcc_core_orphaned_post_repair';

    /** Action Scheduler group label. */
    private const GROUP = 'bcc-core';

    /**
     * ⛔ BOUNDED. A repair that retried forever would turn a database fault
     * into an unbounded queue, and the thing it is chasing is already a
     * best-effort repair. After this many attempts it records
     * `orphan_repair_abandoned` and stops, so the give-up is visible rather
     * than silent.
     */
    private const MAX_ATTEMPTS = 5;

    /** Seconds between attempts. Long enough for a transient fault to clear. */
    private const RETRY_DELAY = 300;

    /**
     * ⚠⚠ THE EVENT NAMES ARE INLINE LITERALS AT THE CALL SITES, not
     * constants, and that is required rather than careless.
     *
     * `DegradationEventRegistrationTest` scans every
     * `DegradationMetrics::record(` call in `src/` with a regex that only
     * reads a ONE-LINE LITERAL subsystem and event, and fails on any call it
     * cannot parse. That scan is what ties each call site to the canonical
     * subsystem map in `bcc-core.php`, so a constant here would buy tidiness
     * at the cost of the check that keeps the map honest.
     *
     * The two events, for reference — both live in the `peepso_absence`
     * subsystem:
     *   orphan_repair_unconfirmed  the destroy could not be confirmed, so a
     *                              best-effort trash ran and a retry queued
     *   orphan_repair_abandoned    the bounded retry gave up with the post
     *                              still unconfirmed
     */

    /**
     * Called immediately after the destroy, in the hijack branch.
     *
     * ⚠ Deliberately returns void and throws nothing: it runs inside a path
     * that is already refusing a write, and a failure to REPAIR must never
     * change that refusal into something else.
     */
    public static function afterDestroy(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        self::attemptTrash($postId);

        // The probed read decides only whether to REPORT and queue — never
        // whether to act. Acting already happened, unconditionally, above.
        $status = PeepSoActivityRepository::postStatusOrUnknown($postId);

        if ($status === PeepSoActivityRepository::POST_GONE || $status === 'trash') {
            return;
        }

        // Either the post is still live, or the read could not be trusted.
        // ⛔ UNKNOWN queues too: the one thing worse than a needless retry is
        // an orphan nobody is looking for.
        DegradationMetrics::record('peepso_absence', 'orphan_repair_unconfirmed');

        AsyncDispatcher::scheduleSingle(
            time() + self::RETRY_DELAY,
            self::RETRY_HOOK,
            [$postId, 1],
            self::GROUP
        );
    }

    /**
     * The retry. Re-checks, re-trashes, and reschedules until the post is gone
     * or confirmed trashed — or until {@see MAX_ATTEMPTS} is reached, which
     * records `orphan_repair_abandoned`.
     *
     * ⚠ Bound to a hook, so the signature is positional: Action Scheduler and
     * `wp_schedule_single_event` both bind `$args` BY INDEX.
     */
    public static function retry(int $postId = 0, int $attempt = 1): void
    {
        if ($postId <= 0) {
            return;
        }

        $status = PeepSoActivityRepository::postStatusOrUnknown($postId);
        if ($status === PeepSoActivityRepository::POST_GONE || $status === 'trash') {
            return;
        }

        // Still live, or still unreadable. Try again before deciding.
        self::attemptTrash($postId);

        $after = PeepSoActivityRepository::postStatusOrUnknown($postId);
        if ($after === PeepSoActivityRepository::POST_GONE || $after === 'trash') {
            return;
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            DegradationMetrics::record('peepso_absence', 'orphan_repair_abandoned');

            return;
        }

        AsyncDispatcher::scheduleSingle(
            time() + self::RETRY_DELAY,
            self::RETRY_HOOK,
            [$postId, $attempt + 1],
            self::GROUP
        );
    }

    /**
     * The write itself: pinned, and `wp_update_post` rather than
     * `wp_trash_post()` for the reasons in the class docblock.
     *
     * ⚠ The return value is NOT read. `wp_update_post` answers 0 both for "no
     * such post" and for "its own get_post() could not be trusted", so the
     * value cannot tell success from an unreadable table. The probed read in
     * the callers is what decides anything.
     */
    private static function attemptTrash(int $postId): void
    {
        PeepSoAddPostGuard::pinOnly(
            [], // a repair drives nothing through the request
            static fn (): bool => (bool) wp_update_post([
                'ID'          => $postId,
                'post_status' => 'trash',
            ])
        );
    }
}
