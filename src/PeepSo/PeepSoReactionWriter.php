<?php
/**
 * PeepSoReactionWriter — thin wrapper around PeepSoReactionsModel for
 * set/remove of a viewer's reaction on a peepso_activities row.
 *
 * BCC must NOT INSERT directly into peepso_reactions (single-graph
 * rule, mirrors PeepSoFollowWriter). PeepSo's reactions model owns
 * notification fan-out, like-count caching, the
 * `peepso_action_react_add` / `peepso_action_react_remove` hooks
 * other plugins listen to, and idempotency on swap (set replaces
 * any existing reaction by the same user on the same activity).
 *
 * Contract:
 *   - setReaction(actId, reactionTypeId): bool
 *       Replaces any existing reaction the current user has on this
 *       activity with the given type. PeepSoReactionsModel reads the
 *       acting user from `get_current_user_id()`, so callers must
 *       have set the right user first (BCC's BearerAuth middleware
 *       handles that for REST routes). Idempotent on same-type set.
 *
 *   - removeReaction(actId): bool
 *       Removes the current user's reaction on this activity, if any.
 *       Idempotent — removing a non-existent reaction is a no-op.
 *
 * Returns false on:
 *   - PeepSoReactionsModel class missing (PeepSo deactivated)
 *   - invalid IDs (zero or negative)
 *   - activity row missing / already deleted
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, §D5 reactions)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoReactionWriter
{
    /**
     * Set (or replace) the current user's reaction on the given activity.
     */
    public static function setReaction(int $actId, int $reactionTypeId): bool
    {
        if ($actId <= 0 || $reactionTypeId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoReactionsModel')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'reaction_writer_set');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }

        $model = new \PeepSoReactionsModel();
        // init() loads the activity row, populates act_module_id /
        // act_external_id, and reads any existing reaction for the
        // current user. user_reaction_set internally calls
        // user_reaction_reset first, so swap is one call.
        $model->init($actId);
        // init() populates act_external_id from the peepso_activities row
        // it just loaded; a missing/deleted activity leaves it null
        // (PeepSo's get_activity() returns NULL — its own TODO admits
        // callers must check). Bail BEFORE user_reaction_set — calling it
        // on an uninitialized model would INSERT an orphan
        // peepso_reactions row keyed at a nonexistent act_id. NB: PeepSo
        // gives no per-write status (user_reaction_set returns TRUE
        // unconditionally), so a raw INSERT failure remains unobservable
        // here by design — accepted; it surfaces in $wpdb->last_error.
        if ((int) $model->act_external_id <= 0) {
            return false;
        }
        $model->user_reaction_set($reactionTypeId);

        return true;
    }

    /**
     * Remove the current user's reaction on the given activity.
     */
    public static function removeReaction(int $actId): bool
    {
        if ($actId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoReactionsModel')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'reaction_writer_remove');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }

        $model = new \PeepSoReactionsModel();
        $model->init($actId);
        // Same missing-activity gate as setReaction — reset on an
        // uninitialized model does null-property reads inside PeepSo.
        if ((int) $model->act_external_id <= 0) {
            return false;
        }
        $model->user_reaction_reset();

        return true;
    }
}
