<?php
/**
 * PeepSoPagePrivacy — the one rule every BCC surface uses for SECRET pages.
 *
 * PeepSo stores a page's privacy as the `peepso_page_privacy` post meta:
 * 0 open, 1 closed, 2 secret (PeepSo's own `\PeepSoPagePrivacy::PRIVACY_SECRET`).
 * A secret page is still `post_status = 'publish'`, so a status filter alone
 * shows it. BCC reads pages with raw SQL, which PeepSo's own `posts_clauses`
 * privacy filter never sees, so every BCC read has to apply the rule itself.
 *
 * The rule:
 *   - Lists and search never show a secret page, to anyone. Put
 *     `notSecretSql()` in the WHERE clause.
 *   - One page on its own is shown to a member of that page (any `member*`
 *     status, the owner included) and to nobody else. Ask `isVisibleTo()`.
 *   - A read that fails counts as secret. Hiding an open page for one
 *     request costs less than showing a secret one.
 *   - Closed pages (1) are NOT hidden: anyone may see that they exist.
 *
 * ⚠ "Secret" means ANY privacy row for the page reads as 2. Nothing stops a
 *   page from carrying two `peepso_page_privacy` rows (add_post_meta without
 *   $unique). The older `LEFT JOIN … AND (meta_value IS NULL OR <> 2)` form
 *   let such a page through when one row said 0, and returned the page once
 *   per row. NOT EXISTS does neither.
 *
 * ⚠ Membership is stricter than PeepSo's own page view, which also admits
 *   `pending_user` (invited, not yet joined). Here an invitation alone does
 *   not open a secret page.
 *
 * ⚠ Not PeepSo's global `\PeepSoPagePrivacy` (its privacy constants). This
 *   class is namespaced and holds BCC's reads; nothing in BCC uses PeepSo's.
 *
 * No writes. Uncached: the single-page check backs a privacy gate.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoPagePrivacy
{
    use ProbesReads;

    public const META_KEY = 'peepso_page_privacy';
    public const SECRET   = 2;

    private const MEMBERS_TABLE_SUFFIX = 'peepso_page_members';

    /**
     * Rows read for one (page, user) pair. A pair normally has one row, but
     * peepso_page_members has no unique (page, user) key.
     */
    private const PAIR_ROW_CAP = 10;

    /**
     * A WHERE condition that is TRUE when the page whose id is `$idColumn`
     * is not secret.
     *
     * Use it on every list or search read of `peepso-page` posts:
     *
     *     $where[] = PeepSoPagePrivacy::notSecretSql('p.ID');
     *
     * The fragment contains no `%` and no placeholders, so it can go into a
     * string that is later passed to `$wpdb->prepare()`.
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
     * Is this page secret? A failed read answers true.
     *
     * The same condition as `notSecretSql()`, asked for one page.
     */
    public static function isSecretOrUnknown(int $pageId): bool
    {
        if ($pageId <= 0) {
            return true;
        }

        global $wpdb;
        $sql = $wpdb->prepare(self::secretRowSql('%d') . ' LIMIT 1', $pageId);

        if (!self::runProbe($sql)) {
            return true;
        }

        return $wpdb->get_var(null) !== null;
    }

    /**
     * May this viewer see this page on its own (its card, its profile)?
     *
     * True for a page that is not secret. For a secret page, true only when
     * the viewer is a member of it: a `member*` status (member, moderator,
     * manager, owner) and no `banned` row. Anonymous viewers, invited users,
     * banned users and failed reads all get false.
     *
     * It does not check that the page exists or is published; callers do
     * that first.
     */
    public static function isVisibleTo(int $pageId, int $viewerId): bool
    {
        if ($pageId <= 0) {
            return false;
        }
        if (!self::isSecretOrUnknown($pageId)) {
            return true;
        }

        return $viewerId > 0 && self::isMember($pageId, $viewerId);
    }

    /**
     * The viewer holds a `member*` row on the page and no `banned` row.
     * A failed read answers false.
     *
     * Banned rows sort first, so the row cap can never hide a ban behind
     * older member rows.
     */
    private static function isMember(int $pageId, int $userId): bool
    {
        global $wpdb;
        $members = $wpdb->prefix . self::MEMBERS_TABLE_SUFFIX;

        $sql = $wpdb->prepare(
            "SELECT pm_user_status
               FROM {$members}
              WHERE pm_page_id = %d
                AND pm_user_id = %d
              ORDER BY (pm_user_status = 'banned') DESC, pm_id ASC
              LIMIT %d",
            $pageId,
            $userId,
            self::PAIR_ROW_CAP
        );

        if (!self::runProbe($sql)) {
            return false;
        }

        $member = false;
        foreach ($wpdb->get_col(null) as $status) {
            $status = (string) $status;
            if ($status === 'banned') {
                return false;
            }
            if (str_starts_with($status, 'member')) {
                $member = true;
            }
        }
        return $member;
    }

    /**
     * The one secret-row predicate both public reads share: a privacy row
     * for the page whose value reads as 2. `CAST … AS UNSIGNED` matches the
     * comparison the bcc-search and bcc-trust copies used before this class.
     */
    private static function secretRowSql(string $idSql): string
    {
        global $wpdb;

        return "SELECT 1 FROM {$wpdb->postmeta} bcc_page_priv"
            . " WHERE bcc_page_priv.post_id = {$idSql}"
            . " AND bcc_page_priv.meta_key = '" . self::META_KEY . "'"
            . ' AND CAST(bcc_page_priv.meta_value AS UNSIGNED) = ' . self::SECRET;
    }
}
