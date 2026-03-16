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

    // ── Internal ────────────────────────────────────────────────────────────

    private static function ensureInit(): void
    {
        if (self::$initialised) {
            return;
        }

        self::$initialised = true;
        self::$log_file    = WP_CONTENT_DIR . '/bcc.log';
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

        if (self::$log_file && @file_put_contents(self::$log_file, $entry, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }

        // Fallback.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[BCC] ' . $entry);
    }
}