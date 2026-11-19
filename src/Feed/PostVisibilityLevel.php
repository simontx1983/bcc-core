<?php
/**
 * PostVisibilityLevel — the §F-02 ladder as a value: who a post reaches.
 *
 * A post is visible at the STRICTER of its own `act_access` and its
 * AUTHOR's `peepso_users.usr_profile_acc`, so a profile set to "friends"
 * or "only me" also narrows the posts already written under it.
 *
 * This class is the rule's ONE home, in both languages:
 *   - PHP: effective() and rung(), for callers that already hold the row
 *     and need the LEVEL rather than a yes/no for one viewer. The first is
 *     bcc-trust's repost gate: only a post every member can see may be
 *     reposted.
 *   - SQL: sqlEffective(), sqlStoredLevel() and sqlLadder(), which every
 *     SQL read applying the ladder is built from. That covers the feed and
 *     the permalink (`PeepSoActivityRepository::f02PostVisibilityClause()`),
 *     the follower lists (the listed user's profile level alone) and
 *     public trending (the PUBLIC rung only).
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
 * ⚠ The SQL twins and the PHP methods must agree on every input.
 * `PostVisibilityLevelSqlAgreementTest` runs the real rendered activity
 * and follower clauses over a grid of stored values (NULL, 0, -1, the
 * four rungs, the values between them, 255, and an author with no row).
 * It checks them against effective() and rung() for every kind of viewer.
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

    // ── The SQL twins ───────────────────────────────────────────────
    //
    // Every SQL read that applies this ladder builds it from these, so
    // the ladder has one home in SQL too: the activity reads
    // (PeepSoActivityRepository), the follower lists
    // (PeepSoFollowerRepository) and public trending
    // (PeepSoHashtagRepository). Snapping happens in sqlLadder()'s range
    // arms; the level expressions only normalise NULL and <= 0.

    /**
     * One stored level as SQL, with NULL and `<= 0` read as PUBLIC.
     * A NULL left as it is would match no `<=` arm in sqlLadder() and be
     * DENIED, so it must be normalised first. The follower lists use it
     * on the listed user's `usr_profile_acc`, which is the whole level
     * there: a follow row has no `act_access`.
     *
     * @param string $column a trusted, code-supplied column reference, never input
     */
    public static function sqlStoredLevel(string $column): string
    {
        return 'IF(' . $column . ' IS NULL OR ' . $column . ' <= 0, ' . self::PUBLIC . ', ' . $column . ')';
    }

    /**
     * {@see effective()} as SQL: GREATEST picks the stricter, because the
     * ladder is ordered.
     *
     * ⚠ The profile side is COALESCEd, not passed through sqlStoredLevel():
     * a profile at 0 or -1 then yields a value below 10, and GREATEST hands
     * back the post side, which is never below 10. Same answer as
     * effective(), which reads both as public. Kept as it is because every
     * statement that uses it is pinned byte for byte.
     *
     * @param string $actAccess     a trusted, code-supplied column reference, never input
     * @param string $profileAccess a trusted, code-supplied column reference, never input
     */
    public static function sqlEffective(string $actAccess, string $profileAccess): string
    {
        return 'GREATEST('
            . self::sqlStoredLevel($actAccess) . ','
            . ' COALESCE(' . $profileAccess . ', ' . self::PUBLIC . ')'
            . ')';
    }

    /**
     * The viewer-facing ladder over an already-built level expression:
     * the owner always sees their own rows, then `<= PUBLIC` admits
     * everyone, `<= MEMBERS` any signed-in viewer, `<= FRIENDS` whatever
     * `$friendBranch` admits, and anything above DENIES. There is
     * deliberately no admitting catch-all.
     *
     * ⚠ PLACEHOLDERS, in order: the viewer id, the viewer id, the viewer
     * id, then any `$friendBranch` carries. The caller binds them in that
     * order, at the position where it puts this fragment.
     *
     * @param string $ownerColumn  whose rows these are: the post author, or the listed user
     * @param string $level        from sqlEffective() or sqlStoredLevel()
     * @param string $friendBranch SQL yielding 1 or 0 for the friends rung; the literal '0' when the viewer has no friends to admit
     */
    public static function sqlLadder(string $ownerColumn, string $level, string $friendBranch): string
    {
        return 'CASE WHEN %d > 0 AND ' . $ownerColumn . ' = %d THEN 1'
            . ' ELSE CASE'
            .   ' WHEN ' . $level . ' <= ' . self::PUBLIC . ' THEN 1'
            .   ' WHEN ' . $level . ' <= ' . self::MEMBERS . ' THEN IF(%d > 0, 1, 0)'
            .   ' WHEN ' . $level . ' <= ' . self::FRIENDS . ' THEN ' . $friendBranch
            .   ' ELSE 0'
            . ' END END';
    }
}
