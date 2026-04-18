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
     *
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * Log a security-relevant audit event.
     *
     * @param array<string, mixed> $context
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

        // Prefer a log directory OUTSIDE the web root for security.
        // ABSPATH is the WordPress root (inside webroot), so we go one
        // level above. Fallback to wp-content/bcc-logs if the parent
        // directory is not writable (shared hosting restrictions).
        $preferred_dir = dirname(ABSPATH) . '/bcc-logs';
        $fallback_dir  = WP_CONTENT_DIR . '/bcc-logs';

        // Allow override via constant in wp-config.php.
        if (defined('BCC_LOG_DIR')) {
            $log_dir = BCC_LOG_DIR;
        } elseif (is_writable(dirname(ABSPATH)) || is_dir($preferred_dir)) {
            $log_dir = $preferred_dir;
        } else {
            $log_dir = $fallback_dir;
        }

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Protect log directory from web access.
        // The .htaccess covers Apache; the <Files> directive with a wildcard
        // also blocks direct file access. Nginx ignores .htaccess, so we
        // additionally use a randomized filename to prevent URL guessing.
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, implode("\n", [
                '# Deny all access to this directory',
                '<IfModule mod_authz_core.c>',
                'Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                'Order deny,allow',
                'Deny from all',
                '</IfModule>',
                '# Block direct file access as well',
                '<Files "*">',
                '<IfModule mod_authz_core.c>',
                'Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                'Order deny,allow',
                'Deny from all',
                '</IfModule>',
                '</Files>',
                '',
            ]));
        }

        // Prevent directory listing and direct access on non-Apache servers.
        $index = $log_dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        // Use a randomized log filename so the file cannot be guessed on
        // servers where .htaccess is ignored (e.g., Nginx). The random
        // suffix is generated once and stored as a wp_option so it persists
        // across requests but is not predictable.
        $optionKey   = 'bcc_log_file_secret';
        $logSecret   = get_option($optionKey);
        if (!is_string($logSecret) || strlen($logSecret) < 32) {
            $logSecret = bin2hex(random_bytes(16));
            update_option($optionKey, $logSecret, false);
        }

        self::$log_file = $log_dir . '/bcc-' . $logSecret . '.log';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        self::ensureInit();

        $timestamp = gmdate('Y-m-d H:i:s') . ' UTC';
        $entry     = sprintf('[%s] [%s] %s', $timestamp, $level, $message);

        if ($context) {
            $context = self::redactSensitive($context);
            $json    = wp_json_encode($context, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                // JSON encoding failed (circular ref, depth limit, invalid UTF-8).
                // Log the failure itself so context loss is visible.
                $entry .= ' {"_json_error":"' . json_last_error_msg() . '"}';
            } else {
                // Strip control characters (newlines, tabs, etc.) from JSON
                // to prevent log injection attacks that forge fake log entries.
                $entry .= ' ' . preg_replace('/[\x00-\x1F\x7F]/', '', $json);
            }
        }

        $entry .= PHP_EOL;

        if (self::$log_file) {
            // Rotate log file if it exceeds 5 MB.
            // Use flock to prevent concurrent workers from both rotating
            // at the same time (second rename would overwrite the first's backup).
            $lockFile = self::$log_file . '.lock';
            $lockFp   = @fopen($lockFile, 'c');
            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                if (file_exists(self::$log_file) && @filesize(self::$log_file) > 5 * 1024 * 1024) {
                    $rotated = self::$log_file . '.' . gmdate('Ymd_His') . '.old';
                    @rename(self::$log_file, $rotated);
                }
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            } elseif ($lockFp) {
                fclose($lockFp);
            }

            if (@file_put_contents(self::$log_file, $entry, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }

            // Primary log write failed — alert via PHP error system so the
            // failure is visible in server error logs even if error_log() below
            // also fails. This prevents critical audit events from vanishing
            // silently (e.g., dispute resolutions, fraud detections).
            // Rate-limit the alert to once per request to avoid log storms.
            static $alertedThisRequest = false;
            if (!$alertedThisRequest) {
                $alertedThisRequest = true;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    '[BCC Logger] Primary log file write failed: ' . self::$log_file
                    . ' — falling back to error_log(). Check disk space and permissions.',
                    E_USER_WARNING
                );
            }
        }

        // Fallback.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[BCC] ' . $entry);
    }

    /**
     * Redact sensitive values from log context arrays.
     *
     * Wallet addresses: show first 6 + last 4 chars (e.g. "0x1234...abcd").
     * API keys: fully redacted.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redactSensitive(array $context): array
    {
        $sensitiveKeys = ['address', 'wallet_address', 'walletAddress', 'apikey', 'api_key'];

        foreach ($context as $key => &$value) {
            if (is_array($value)) {
                $value = self::redactSensitive($value);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $lowerKey = strtolower($key);

            // Full redaction for API keys.
            if (in_array($lowerKey, ['apikey', 'api_key'], true)) {
                $value = '***REDACTED***';
                continue;
            }

            // Partial redaction for wallet addresses — keep first 6 + last 4.
            if (in_array($lowerKey, ['address', 'wallet_address', 'walletaddress'], true) && strlen($value) > 12) {
                $value = substr($value, 0, 6) . '...' . substr($value, -4);
            }
        }
        unset($value);

        return $context;
    }
}