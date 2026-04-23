<?php

namespace BCC\Core\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Async / scheduled action dispatcher.
 *
 * Single entry point for "fire-and-forget" deferred work across the BCC
 * ecosystem. Prefers Action Scheduler when the host plugin is active
 * (better visibility, retries, parallel runners) and falls back to
 * `wp_schedule_single_event` so callers do not need a runtime branch.
 *
 * ## Why centralised
 *
 * `wp_schedule_single_event` and `as_enqueue_async_action` both treat the
 * `$args` array positionally — the receiving callback's parameters are bound
 * by index, not by key. A sparse / string-keyed array silently mis-binds
 * parameters or sends `null` where the callback expected a value, and the
 * resulting bug surfaces as a phantom failure inside the worker, far from
 * the original caller. This wrapper asserts `array_is_list($args)` at the
 * dispatch site so a typo throws *here* instead of corrupting the worker.
 *
 * The `wp_next_scheduled` idempotency check on recurring registration is
 * also re-implemented in every plugin's main file — that one is collapsed
 * here too so plugins call `registerRecurring()` once and move on.
 */
class AsyncDispatcher
{
    /**
     * Enqueue a one-shot async action to fire as soon as a worker picks it up.
     *
     * Prefers Action Scheduler's async queue (`as_enqueue_async_action`) when
     * available; falls back to `wp_schedule_single_event(time(), ...)`.
     *
     * @param string     $hook  Hook name the worker will fire.
     * @param list<mixed> $args Callback args. MUST be a zero-indexed gap-free
     *                          list — anything else throws.
     * @param string     $group Action Scheduler group label, used for filtering
     *                          the AS admin UI. Ignored by the wp-cron fallback.
     * @return bool True if the backend accepted the enqueue, false on soft
     *              failure (AS returned 0, wp_schedule_single_event returned
     *              false/WP_Error). Callers MUST check this — a silent false
     *              return leaves the work unclaimed with no retry unless the
     *              caller explicitly reconciles.
     * @throws \LogicException When `$args` is not `array_is_list`.
     */
    public static function enqueueAsync(string $hook, array $args = [], string $group = ''): bool
    {
        self::assertList($args, 'enqueueAsync', $hook);

        if (function_exists('as_enqueue_async_action')) {
            // Action Scheduler returns the action ID on success, 0 on failure.
            $actionId = as_enqueue_async_action($hook, $args, $group);
            return is_int($actionId) ? $actionId > 0 : (bool) $actionId;
        }

        // Even with DISABLE_WP_CRON, wp_schedule_single_event queues the
        // event in the database. It will fire on the next HTTP request that
        // invokes wp-cron.php (which managed hosts trigger via system cron).
        // Returns true/false/WP_Error (WP 5.1+); null on older WP (treat as ok).
        $scheduled = wp_schedule_single_event(time(), $hook, $args);
        if ($scheduled === null) {
            return true; // Legacy WP signalled success via null return.
        }
        return $scheduled === true;
    }

    /**
     * Schedule a one-shot action to fire at (or after) a specific timestamp.
     *
     * Prefers `as_schedule_single_action` when Action Scheduler is available;
     * falls back to `wp_schedule_single_event($timestamp, ...)`.
     *
     * Idempotency: the wp-cron fallback de-dupes by (hook, args, timestamp),
     * but Action Scheduler does not — if you need at-most-one semantics across
     * both backends, gate the call yourself with `wp_next_scheduled` or an
     * `as_has_scheduled_action` check before invoking.
     *
     * @param int        $timestamp UNIX timestamp; pass `time() + N` for a relative delay.
     * @param string     $hook
     * @param list<mixed> $args     MUST be `array_is_list`-compliant.
     * @param string     $group     Action Scheduler group label.
     * @throws \LogicException When `$args` is not `array_is_list`.
     */
    public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
    {
        self::assertList($args, 'scheduleSingle', $hook);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, $group);
            return;
        }

        wp_schedule_single_event($timestamp, $hook, $args);
    }

    /**
     * Idempotently register a recurring wp-cron event.
     *
     * Equivalent to the `if (!wp_next_scheduled($hook)) wp_schedule_event(...)`
     * pattern repeated in every plugin's main file. Stays on wp-cron rather
     * than Action Scheduler because the AS recurring API is not portably
     * available outside WooCommerce-flavoured stacks.
     *
     * @param string $hook
     * @param string $interval One of WP's registered intervals (`hourly`,
     *                         `daily`, etc., plus any custom intervals
     *                         registered via `cron_schedules` filter).
     * @param int|null $firstRun Timestamp of first invocation. Defaults to
     *                           `time()` (next cron tick).
     * @return bool True if a new event was scheduled, false if one already exists.
     */
    public static function registerRecurring(string $hook, string $interval, ?int $firstRun = null): bool
    {
        if (wp_next_scheduled($hook)) {
            return false;
        }
        wp_schedule_event($firstRun ?? time(), $interval, $hook);
        return true;
    }

    /**
     * @param list<mixed> $args
     */
    private static function assertList(array $args, string $method, string $hook): void
    {
        if (!array_is_list($args)) {
            throw new \LogicException(
                "AsyncDispatcher::{$method}({$hook}): args must be a zero-indexed list"
            );
        }
    }
}
