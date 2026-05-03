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
            return false;
        }

        $model = new \PeepSoReactionsModel();
        // init() loads the activity row, populates act_module_id /
        // act_external_id, and reads any existing reaction for the
        // current user. user_reaction_set internally calls
        // user_reaction_reset first, so swap is one call.
        $model->init($actId);
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
            return false;
        }

        $model = new \PeepSoReactionsModel();
        $model->init($actId);
        $model->user_reaction_reset();

        return true;
    }
}
