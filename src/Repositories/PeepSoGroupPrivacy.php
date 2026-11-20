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
 * ⚠ The deny-list read uses `nonOpenSql()`, NEVER `notSecretSql()`.
 *   {@see PeepSoGroupRepository::getNonOpenGroupIdsStrict()} asks the
 *   opposite question — "which groups are closed OR secret" — to build an
 *   exclusion set, and an allow-list predicate would invert its safety. `nonOpenSql()` is
 *   `EXISTS (a row that reads 1 or 2)`: it matches a two-row group (the
 *   fail-closed direction) and returns each group once.
 *
 * ⚠ ONE READING OF THE VALUE. Every reader here goes through `valueSql()`,
 *   `CAST(meta_value AS UNSIGNED)`, so the list predicate, the deny
 *   predicate and the single-group read always agree on what a stored value
 *   means (`'2'`, `' 2'`, `'02'` are all 2). Never compare `meta_value` as a
 *   string in a privacy read.
 *
 * ⚠ Not PeepSo's own global `PeepSoGroupPrivacy` (its privacy constants).
 *   This class is namespaced and holds BCC's reads.
 *
 * ── The single-group read (F-460) ────────────────────────────────────
 *
 * `privacyForGroup()` / `privacyForGroups()` answer "what IS this group's
 * privacy" for every BCC PHP caller: bcc-trust's `PeepSoPrivacy` (and through
 * it GroupContextResolver and every group gate), and core's
 * `PeepSoStatusWriter::attachToGroup()`.
 *
 * ⚠⚠ Read through ProbesReads, never get_post_meta(): a read that cannot be
 * trusted answers null, and callers fail closed. These reads go to the table
 * through `ProbesReads::trustedRows()`, which also answers null on wpdb's
 * pre-`flush()` early return (`query()` false with no `last_error`).
 *
 * Same rule as `notSecretSql()`: the STRICTEST row wins (any 2 → secret,
 * else any 1 → closed), read with the same `CAST … AS UNSIGNED`, so the
 * single-group answer can never disagree with the list predicate. A group
 * with NO privacy row is open (0), for the reason given above.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoGroupPrivacy
{
    use ProbesReads;

    public const META_KEY = 'peepso_group_privacy';
    public const OPEN     = 0;
    public const CLOSED   = 1;
    public const SECRET   = 2;

    /** Ceiling on ids per `privacyForGroups()` call, for §3 bounded queries. */
    public const BATCH_MAX = 500;

    /**
     * This group's privacy: 2 secret, 1 closed, 0 open, or NULL when the
     * read could not be trusted (callers fail closed).
     *
     * The STRICTEST of its privacy rows wins, and a group with no row is
     * open. See the class docblock for why this never goes through
     * `get_post_meta()`.
     */
    public static function privacyForGroup(int $groupId): ?int
    {
        if ($groupId <= 0) {
            return null;
        }

        $byGroup = self::privacyForGroups([$groupId]);

        return $byGroup === null ? null : ($byGroup[$groupId] ?? null);
    }

    /**
     * `privacyForGroup()` for many groups in ONE query, keyed by group id.
     * Every valid id in the input has an entry: a group with no privacy row
     * is 0.
     *
     * NULL when the read could not be trusted, or the input is over
     * `BATCH_MAX`. That is refused, never truncated: truncating would answer
     * for the first N groups and leave the rest unknown with no signal.
     *
     * @param list<int> $groupIds
     * @return array<int, int>|null
     */
    public static function privacyForGroups(array $groupIds): ?array
    {
        $ids = [];
        foreach ($groupIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::BATCH_MAX) {
            return null;
        }

        global $wpdb;
        $rows = self::trustedRows($wpdb->prepare(
            'SELECT post_id, MAX(CASE'
                . ' WHEN ' . self::valueSql() . ' = ' . self::SECRET . ' THEN ' . self::SECRET
                . ' WHEN ' . self::valueSql() . ' = ' . self::CLOSED . ' THEN ' . self::CLOSED
                . ' ELSE ' . self::OPEN . ' END) AS strictest'
                . " FROM {$wpdb->postmeta}"
                . ' WHERE meta_key = %s'
                . ' AND post_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')'
                . ' GROUP BY post_id',
            self::META_KEY,
            ...$ids
        ));
        if ($rows === null) {
            return null;
        }

        $out = array_fill_keys($ids, self::OPEN);
        foreach ($rows as $row) {
            $groupId = (int) ($row->post_id ?? 0);
            if (isset($out[$groupId])) {
                $out[$groupId] = (int) ($row->strictest ?? self::OPEN);
            }
        }

        return $out;
    }

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
        self::assertColumnReference($idColumn, 'notSecretSql');

        return 'NOT EXISTS (' . self::privacyRowSql($idColumn, '= ' . self::SECRET) . ')';
    }

    /**
     * A WHERE condition that is TRUE when the group whose id is `$idColumn`
     * is closed OR secret: the deny-list question.
     *
     *     $where[] = PeepSoGroupPrivacy::nonOpenSql('p.ID');
     *
     * TRUE when ANY privacy row reads 1 or 2, which is exactly when
     * `privacyForGroup()` answers 1 or 2: a two-row group with `'0'` and
     * `'2'` is non-open, and a group with no row is not. It needs no JOIN,
     * so each group comes back once however many rows it carries. The old
     * `INNER JOIN` returned a group once PER matching row, and the duplicate
     * took a `LIMIT` slot.
     *
     * Same contract as `notSecretSql()`: no `%`, no placeholders.
     *
     * @param string $idColumn a column reference such as `p.ID`, never input
     * @throws \InvalidArgumentException when `$idColumn` is not a column reference
     */
    public static function nonOpenSql(string $idColumn): string
    {
        self::assertColumnReference($idColumn, 'nonOpenSql');

        return 'EXISTS (' . self::privacyRowSql($idColumn, 'IN (' . self::CLOSED . ', ' . self::SECRET . ')') . ')';
    }

    /**
     * @throws \InvalidArgumentException when `$idColumn` is not a column reference
     */
    private static function assertColumnReference(string $idColumn, string $method): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $idColumn) !== 1) {
            throw new \InvalidArgumentException($method . '() takes a column reference such as p.ID.');
        }
    }

    /**
     * A privacy row for the group whose value, read by `valueSql()`, passes
     * `$valueTest` (`= 2`, `IN (1, 2)`).
     */
    private static function privacyRowSql(string $idSql, string $valueTest): string
    {
        global $wpdb;

        return "SELECT 1 FROM {$wpdb->postmeta} bcc_group_priv"
            . " WHERE bcc_group_priv.post_id = {$idSql}"
            . " AND bcc_group_priv.meta_key = '" . self::META_KEY . "'"
            . ' AND ' . self::valueSql('bcc_group_priv.') . ' ' . $valueTest;
    }

    /**
     * The ONE reading of a privacy value, shared by every reader in this
     * class. `CAST … AS UNSIGNED` matches the comparison the bcc-core and
     * bcc-search copies used before this class, so a row stored as `'2'`,
     * `'2 '`, `' 2'` or `2` reads the same everywhere. Never compare
     * `meta_value` as a string: see the class docblock.
     */
    private static function valueSql(string $qualifier = ''): string
    {
        return 'CAST(' . $qualifier . 'meta_value AS UNSIGNED)';
    }
}
