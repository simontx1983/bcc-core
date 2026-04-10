<?php

namespace BCC\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight logger for the BCC ecosystem.
 *
 * Writes to a dedicated `bcc.log` file inside `wp-content/` so entries
 * don't get lost in the generic `debug.log`.  Falls back to `error_log()`
 * if the file is not writable.
 */
final class Logger
{
    /** @var string|null Resolved log file path (lazy-initialised on first write). */
    private static ?string $log_file = null;

    /** @var bool Whether the log path has been resolved. */
    private static bool $initialised = false;

    /**
     * Log an informational message.
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Log an error message.
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Log a warning message.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * Log a security-relevant audit event.
     */
    public static function audit(string $message, array $context = []): void
    {
        self::write('AUDIT', $message, $context);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    private static function ensureInit(): void
    {
        if (self::$initialised) {
            return;
        }

        self::$initialised = true;

        $log_dir = WP_CONTENT_DIR . '/bcc-logs';

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Protect log directory from web access (Apache + Nginx + fallback).
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "deny from all\n");
        }

        // Prevent directory listing and direct access on non-Apache servers.
        $index = $log_dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        self::$log_file = $log_dir . '/bcc.log';
    }

    private static function write(string $level, string $message, array $context): void
    {
        self::ensureInit();

        $timestamp = current_time('Y-m-d H:i:s');
        $entry     = sprintf('[%s] [%s] %s', $timestamp, $level, $message);

        if ($context) {
            $entry .= ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $entry .= PHP_EOL;

        if (self::$log_file) {
            // Rotate log file if it exceeds 5 MB.
            if (file_exists(self::$log_file) && filesize(self::$log_file) > 5 * 1024 * 1024) {
                @rename(self::$log_file, self::$log_file . '.old');
            }

            if (@file_put_contents(self::$log_file, $entry, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }

        // Fallback.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[BCC] ' . $entry);
    }
}