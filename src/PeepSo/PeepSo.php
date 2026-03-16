<?php

namespace BCC\Core\PeepSo;

use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adapter for PeepSo page data.
 *
 * Wraps the existing bcc-trust-engine helpers when available and
 * provides direct DB fallbacks otherwise.  Keeps all PeepSo
 * coupling in one place so consumer plugins never touch PeepSo
 * tables directly.
 */
final class PeepSo
{
    /** @var string|null|false Cached member table name (null = not resolved, false = none found). */
    private static $member_table = null;

    /**
     * Resolve the owner (user ID) of a PeepSo page.
     *
     * @return int|null  Owner user ID, or null if not found.
     */
    public static function get_page_owner(int $page_id): ?int
    {
        // Trust-engine helper (authoritative).
        if (function_exists('bcc_trust_get_page_owner')) {
            $owner = bcc_trust_get_page_owner($page_id);
            return $owner ? (int) $owner : null;
        }

        // Direct PeepSo table fallback.
        $table = self::resolve_member_table();

        if ($table === false) {
            return null;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $uid = $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT pm_user_id FROM `" . esc_sql($table) . "` WHERE pm_page_id = %d AND pm_role = 'owner' LIMIT 1",
            $page_id
        ));

        if ($wpdb->last_error) {
            Logger::error('PeepSo get_page_owner query failed', [
                'page_id' => $page_id,
                'error'   => $wpdb->last_error,
            ]);
            return null;
        }

        return $uid ? (int) $uid : null;
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Discover and cache the PeepSo member table name for the current request.
     *
     * @return string|false  Table name, or false if none exists.
     */
    private static function resolve_member_table()
    {
        if (self::$member_table !== null) {
            return self::$member_table;
        }

        global $wpdb;

        $candidates = [
            $wpdb->prefix . 'peepso_page_members',
            $wpdb->prefix . 'peepso_page_users',
            $wpdb->prefix . 'peepso_pages_users',
        ];

        foreach ($candidates as $candidate) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $candidate)
            );

            if ($exists) {
                self::$member_table = $candidate;
                return self::$member_table;
            }
        }

        self::$member_table = false;
        return false;
    }
}