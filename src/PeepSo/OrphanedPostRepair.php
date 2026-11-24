<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Cron\AsyncDispatcher;
use BCC\Core\Log\Logger;
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

        // ⛔ EVERY STEP IS INDIVIDUALLY CONTAINED, and the order below is the
        // point: a step that throws must not stop the ones after it. A failed
        // TRASH still gets probed and still gets queued — losing the retry
        // because the write blew up would turn a recoverable orphan into a
        // permanent one, which is the exact failure this class exists to
        // close.
        self::tryTrash($postId);

        // The probed read decides only whether to REPORT and queue — never
        // whether to act. Acting already happened, unconditionally, above.
        $status = self::tryProbe($postId);

        if (self::isSettled($status)) {
            return;
        }

        // Either the post is still live, or the read could not be trusted.
        // ⛔ UNKNOWN queues too: the one thing worse than a needless retry is
        // an orphan nobody is looking for.
        self::reportUnconfirmed($postId, 1, true);

        self::trySchedule($postId, 1);
    }

    /**
     * The retry. Re-checks, re-trashes, and reschedules until the post is gone
     * or confirmed trashed — or until {@see MAX_ATTEMPTS} is reached, which
     * records `orphan_repair_abandoned`.
     *
     * ⚠ Bound to a hook, so the signature is positional: Action Scheduler and
     * `wp_schedule_single_event` both bind `$args` BY INDEX.
     *
     * ⛔ Contained step by step for the same reason as {@see afterDestroy()},
     * and it matters more here: this runs under a scheduler, and a throw
     * escaping into Action Scheduler marks the action failed, which can stop
     * the chain that is the actual guarantee.
     */
    public static function retry(int $postId = 0, int $attempt = 1): void
    {
        if ($postId <= 0) {
            return;
        }

        if (self::isSettled(self::tryProbe($postId))) {
            return;
        }

        // Still live, or still unreadable. Try again before deciding.
        self::tryTrash($postId);

        if (self::isSettled(self::tryProbe($postId))) {
            return;
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            self::reportAbandoned($postId, $attempt);

            return;
        }

        // ⚠ The LOG line is written on every unconfirmed attempt, but the
        // METRIC is deliberately NOT re-recorded here — `afterDestroy()`
        // already counted this orphan once, and counting it again per attempt
        // would multiply a single orphan into up to MAX_ATTEMPTS activations
        // on /system/health. The log is what carries the per-attempt detail,
        // and it is the thing ops reads post_ids out of.
        self::reportUnconfirmed($postId, $attempt, false);

        self::trySchedule($postId, $attempt + 1);
    }

    /**
     * Gone, or confirmed in the trash. NULL is NOT settled — an unreadable
     * answer is the whole reason this class exists.
     */
    private static function isSettled(?string $status): bool
    {
        return $status === PeepSoActivityRepository::POST_GONE || $status === 'trash';
    }

    /**
     * ⚠ A THROWN probe is UNKNOWN, not "gone". Treating a thrown read as
     * settled would be the original fail-open wearing a different hat.
     */
    private static function tryProbe(int $postId): ?string
    {
        try {
            return PeepSoActivityRepository::postStatusOrUnknown($postId);
        } catch (\Throwable $e) {
            self::tryLog('[bcc-core] orphaned post repair: the status read threw', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** The trash write, contained. Its result is not read; see the class docblock. */
    private static function tryTrash(int $postId): void
    {
        try {
            self::attemptTrash($postId);
        } catch (\Throwable $e) {
            // A listener woken by the write threw, or the write itself did.
            // Nothing to decide here: the probe below answers, and an
            // unconfirmed answer queues.
            self::tryLog('[bcc-core] orphaned post repair: the trash write threw', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * ⚠⚠ THE FILE LOG IS WRITTEN FIRST, AND THAT ORDER IS LOAD-BEARING.
     * `DegradationMetrics` and the scheduler both reach for the database, so
     * in the outage this class is most likely to be running in, they are the
     * two things most likely to be lost. `AsyncDispatcher::scheduleSingle()`
     * is `void`, so a schedule that never landed is INVISIBLE — the log line
     * is the only durable record that this post needs looking at.
     *
     * ⇒ Ops runbook: re-check every `post_id` in these lines whose post is
     * not actually gone or trashed. See docs/GOLDEN_PATHS.md.
     */
    private static function reportUnconfirmed(int $postId, int $attempt, bool $record): void
    {
        self::tryLog('[bcc-core] orphaned post repair could not be confirmed', [
            'post_id' => $postId,
            'attempt' => $attempt,
        ]);

        if ($record) {
            self::recordUnconfirmed();
        }
    }

    /** The give-up, which must be visible rather than silent. */
    private static function reportAbandoned(int $postId, int $attempt): void
    {
        self::tryLog('[bcc-core] orphaned post repair gave up with the post unconfirmed', [
            'post_id'  => $postId,
            'attempts' => $attempt,
        ]);

        self::recordAbandoned();
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function tryLog(string $message, array $context): void
    {
        try {
            Logger::error($message, $context);
        } catch (\Throwable $e) {
            // A log that cannot be written is not a reason to drop the repair.
            unset($e);
        }
    }

    /**
     * ⚠⚠ TWO NEAR-IDENTICAL HELPERS, NOT ONE TAKING THE EVENT AS A
     * PARAMETER, and the duplication is REQUIRED rather than sloppy.
     *
     * `DegradationEventRegistrationTest` reads every `record()` call in `src/`
     * with a regex that only accepts one-line LITERAL arguments, because that
     * scan is what ties each call site to the canonical subsystem map in
     * `bcc-core.php`. `record('peepso_absence', $event)` is unreadable to it
     * and fails the suite — centralising these would hide both events from
     * the only check that keeps the map honest.
     *
     * ⚠ This file has now failed that test twice while being written: once
     * for using constants, once for using a parameter. If a third form
     * suggests itself, it is probably also unreadable — keep the literals.
     */
    private static function recordUnconfirmed(): void
    {
        try {
            DegradationMetrics::record('peepso_absence', 'orphan_repair_unconfirmed');
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /** @see recordUnconfirmed() for why this is not one helper with a parameter. */
    private static function recordAbandoned(): void
    {
        try {
            DegradationMetrics::record('peepso_absence', 'orphan_repair_abandoned');
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    private static function trySchedule(int $postId, int $nextAttempt): void
    {
        try {
            AsyncDispatcher::scheduleSingle(
                time() + self::RETRY_DELAY,
                self::RETRY_HOOK,
                [$postId, $nextAttempt],
                self::GROUP
            );
        } catch (\Throwable $e) {
            // ⚠ Already logged above, which is the point of logging first.
            self::tryLog('[bcc-core] orphaned post repair: the retry could not be queued', [
                'post_id' => $postId,
                'attempt' => $nextAttempt,
                'error'   => $e->getMessage(),
            ]);
        }
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
