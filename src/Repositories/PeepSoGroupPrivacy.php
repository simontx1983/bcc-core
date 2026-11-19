<?php
/**
 * PeepSoGroupPrivacy — the one rule every BCC surface uses for SECRET groups.
 *
 * The group twin of {@see PeepSoPagePrivacy}. PeepSo stores a group's privacy
 * as the `peepso_group_privacy` post meta: 0 open, 1 closed, 2 secret. A
 * secret group is still `post_status = 'publish'`, so a status filter alone
 * lists it. BCC reads groups with raw SQL, which PeepSo's own `posts_clauses`
 * privacy filter never sees, so every BCC read has to apply the rule itself.
 *
 * The rule:
 *   - Lists, browse and search never show a secret group, to anyone. Put
 *     `notSecretSql()` in the WHERE clause.
 *   - Closed groups (1) are NOT hidden: a non-member may see that the group
 *     exists, and PeepSo's privacy keeps its content private. That is a
 *     product decision, not an oversight — see
 *     {@see PeepSoGroupRepository::listBrowsableGroupIds()}.
 *
 * ⚠ "Secret" means ANY privacy row for the group reads as 2. Nothing stops a
 *   group from carrying two `peepso_group_privacy` rows (`add_post_meta`
 *   without `$unique`, a migration, a direct DB edit). The older
 *   `LEFT JOIN … AND (meta_value IS NULL OR <> 2)` form let such a group
 *   through whenever one row said 0, and returned the group ONCE PER ROW.
 *   `NOT EXISTS` does neither. This is the same defect that
 *   {@see PeepSoPagePrivacy} was introduced to fix on the page side; the
 *   group side kept the old form until F-429.
 *
 * ⚠ A group with NO privacy row is NOT secret. That is deliberate and it
 *   matches the page rule: `NOT EXISTS (… = 2)` is true when no row exists.
 *   PeepSo and `createPlainGroup` always write privacy at creation, so the
 *   no-row case is a theoretical edge, and treating it as secret would hide
 *   every group on an install whose meta had not been written yet.
 *
 * ⚠ NOT the deny-list reads. {@see PeepSoGroupRepository::getNonOpenGroupIds()}
 *   and `getNonOpenGroupIdsStrict()` ask the opposite question — "which groups
 *   are closed OR secret" — to build an exclusion set. Their
 *   `INNER JOIN … meta_value IN ('1','2')` already MATCHES a two-row group,
 *   which is the fail-closed direction and correct. Do not route them through
 *   this class: an allow-list predicate would invert their safety.
 *
 * ⚠ Not PeepSo's own global `PeepSoGroupPrivacy` (its privacy constants).
 *   This class is namespaced and holds BCC's reads.
 *
 * No writes, no reads of its own — it returns a SQL fragment and nothing else.
 * The single-group question ("is THIS group secret") is answered elsewhere, by
 * `BCC\Trust\Core\ValueObjects\PeepSoPrivacy::fromGroupPostId()`, so this class
 * deliberately has no `isSecretOrUnknown()` / `isVisibleTo()` twin: there is no
 * caller for one, and an unused privacy helper is worse than none.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoGroupPrivacy
{
    public const META_KEY = 'peepso_group_privacy';
    public const SECRET   = 2;

    /**
     * A WHERE condition that is TRUE when the group whose id is `$idColumn`
     * is not secret.
     *
     * Use it on every list, browse or search read of `peepso-group` posts:
     *
     *     $where[] = PeepSoGroupPrivacy::notSecretSql('p.ID');
     *
     * The fragment contains no `%` and no placeholders, so it can go into a
     * string that is later passed to `$wpdb->prepare()`. It also needs no
     * JOIN, so a caller that only joined postmeta for this check can drop
     * the join — which is what removes the duplicate-row result as well as
     * the leak.
     *
     * @param string $idColumn a column reference such as `p.ID`, never input
     * @throws \InvalidArgumentException when `$idColumn` is not a column reference
     */
    public static function notSecretSql(string $idColumn): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $idColumn) !== 1) {
            throw new \InvalidArgumentException('notSecretSql() takes a column reference such as p.ID.');
        }

        return 'NOT EXISTS (' . self::secretRowSql($idColumn) . ')';
    }

    /**
     * The secret-row predicate: a privacy row for the group whose value reads
     * as 2. `CAST … AS UNSIGNED` matches the comparison the bcc-core and
     * bcc-search copies used before this class, so a row stored as `'2'`,
     * `'2 '` or `2` is caught identically either way.
     */
    private static function secretRowSql(string $idSql): string
    {
        global $wpdb;

        return "SELECT 1 FROM {$wpdb->postmeta} bcc_group_priv"
            . " WHERE bcc_group_priv.post_id = {$idSql}"
            . " AND bcc_group_priv.meta_key = '" . self::META_KEY . "'"
            . ' AND CAST(bcc_group_priv.meta_value AS UNSIGNED) = ' . self::SECRET;
    }
}
