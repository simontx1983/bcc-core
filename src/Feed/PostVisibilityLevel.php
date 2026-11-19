<?php
/**
 * PostVisibilityLevel — the §F-02 ladder as a value: who a post reaches.
 *
 * A post is visible at the STRICTER of its own `act_access` and its
 * AUTHOR's `peepso_users.usr_profile_acc`, so a profile set to "friends"
 * or "only me" also narrows the posts already written under it. The
 * feed, the permalink and every other read apply that rule in SQL, in
 * `PeepSoActivityRepository::f02PostVisibilityClause()`. This class is
 * the same rule for PHP callers that already hold the row and need the
 * LEVEL rather than a yes/no for one viewer. The first one is bcc-trust's
 * repost gate: only a post every member can see may be reposted.
 *
 * ── The ladder ──────────────────────────────────────────────────────
 *
 *   10 PUBLIC   everyone, signed out included
 *   20 MEMBERS  any signed-in member
 *   30 FRIENDS  the author's friends
 *   40 ONLY_ME  nobody but the author
 *
 * These are PeepSo's own values: `PeepSo::ACCESS_PUBLIC` (10),
 * `ACCESS_MEMBERS` (20) and `ACCESS_PRIVATE` (40) in PeepSo core, and
 * `PeepSoFriendsPlugin::ACCESS_FRIENDS` (30) in peepso-friends.
 *
 * ── Rung snapping ───────────────────────────────────────────────────
 *
 * Neither column is a validated enum: PeepSo's `change_post_privacy`
 * stores `$this->_input->int('acc')` unchecked. So a stored value is
 * read by RANGE and lands on the nearest STRICTER rung, never on
 * "invisible":
 *
 *   NULL, <= 10 → 10   (0 is what BCC's own writer stores; -1 is
 *                       PeepSo's ACCESS_FORCE_PUBLIC; PeepSo reads both
 *                       as public)
 *   11 – 20     → 20
 *   21 – 30     → 30
 *   above 30    → 40   (so nothing above "friends" is ever widened)
 *
 * ── An author with no `peepso_users` row is PUBLIC (10) ─────────────
 *
 * That is the decision, and three things already agree on it:
 *   - the SQL clause, whose `COALESCE(pu.usr_profile_acc, 10)` reads a
 *     missing row as 10;
 *   - the column's schema default, 10;
 *   - PeepSo itself: `PeepSoUser`'s constructor INSERTS the missing row
 *     with `usr_profile_acc = PeepSo::ACCESS_PUBLIC`, so a reader going
 *     through `PeepSoUser::get_profile_accessibility()` gets 10 too.
 *
 * ⚠⚠ "NO ROW" IS NOT "COULD NOT READ". Pass null only for a row that
 * does not exist. When the read itself failed (PeepSo absent, a DB error,
 * a non-numeric value), the caller cannot tell the level and must REFUSE
 * before calling this. Passing null there would turn an unknown into
 * "public". bcc-trust's `ProfileAccess::canonicalProfileLevelFor()`
 * answers null for exactly that case, and its callers fail closed on it.
 *
 * ── What this does not decide ───────────────────────────────────────
 *
 * The level, not the viewer's verdict. The SQL also lets an author see
 * their own posts at any level, and lets a caller skip the gate for an
 * admin read. Those exemptions stay with the caller. A group-tagged post
 * follows its GROUP's rules, which are also the caller's.
 *
 * ⚠ The SQL twin and this method must agree on every input.
 * `PostVisibilityLevelSqlAgreementTest` runs the real rendered clause
 * over a grid of stored values (NULL, 0, -1, the four rungs, the values
 * between them, 255, and an author with no row) and checks it against
 * this method for every kind of viewer.
 *
 * @package BCC\Core\Feed
 * @since F-02 (one home for the effective post level)
 */

declare(strict_types=1);

namespace BCC\Core\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class PostVisibilityLevel
{
    /** Everyone, signed out included. PeepSo::ACCESS_PUBLIC. */
    public const PUBLIC = 10;

    /** Any signed-in member. PeepSo::ACCESS_MEMBERS. */
    public const MEMBERS = 20;

    /** The author's friends. PeepSoFriendsPlugin::ACCESS_FRIENDS. */
    public const FRIENDS = 30;

    /** Nobody but the author. PeepSo::ACCESS_PRIVATE. */
    public const ONLY_ME = 40;

    /**
     * The level a post actually reaches: the stricter of its own
     * `act_access` and its author's `usr_profile_acc`, on the ladder.
     *
     * @param int|null $actAccess           the post's `act_access`
     * @param int|null $authorProfileAccess the author's `usr_profile_acc`; null ONLY when the author has no `peepso_users` row (see the class docblock: a failed read must be refused by the caller, not passed as null)
     * @return int one of PUBLIC, MEMBERS, FRIENDS, ONLY_ME
     */
    public static function effective(?int $actAccess, ?int $authorProfileAccess): int
    {
        return max(self::rung($actAccess), self::rung($authorProfileAccess));
    }

    /**
     * One stored level (a post's `act_access` or a profile's
     * `usr_profile_acc`) snapped onto the ladder by range.
     *
     * @return int one of PUBLIC, MEMBERS, FRIENDS, ONLY_ME
     */
    public static function rung(?int $stored): int
    {
        if ($stored === null || $stored <= self::PUBLIC) {
            return self::PUBLIC;
        }
        if ($stored <= self::MEMBERS) {
            return self::MEMBERS;
        }
        if ($stored <= self::FRIENDS) {
            return self::FRIENDS;
        }

        return self::ONLY_ME;
    }
}
