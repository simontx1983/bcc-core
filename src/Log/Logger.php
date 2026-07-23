<?php

namespace BCC\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight logger for the BCC ecosystem.
 *
 * Writes to `bcc-{secret}.log` in a `bcc-logs/` directory, with a
 * randomized filename so the log cannot be guessed via URL on servers
 * that ignore `.htaccess` (e.g., Nginx). Path resolution order:
 *   1. `BCC_LOG_DIR` constant override (wp-config.php)
 *   2. `dirname(ABSPATH) + '/bcc-logs'` — preferred, outside webroot
 *   3. `WP_CONTENT_DIR + '/bcc-logs'` — fallback when (2) is not writable
 *
 * The directory is hardened with `.htaccess` (Apache) and the
 * randomized filename + `index.php` silence file (Nginx). Logs rotate
 * at 5 MB. Falls back to `error_log()` if the file write fails.
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

        // Phase 4c: stamp every line with the request-scoped correlation id so a
        // multi-step failure (REST → async → cron) and cross-tier issues can be
        // stitched together from the logs. Guarded so logging never depends on
        // RequestContext; an explicit request_id in $context always wins.
        if (!array_key_exists('request_id', $context) && class_exists(\BCC\Core\Http\RequestContext::class)) {
            $context = ['request_id' => \BCC\Core\Http\RequestContext::requestId()] + $context;
        }

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
     * Secret-bearing keys (api keys, tokens, passwords, signatures, …):
     * fully redacted, matched by substring so prefixed names
     * (`helius_api_key`, `refresh_token`) are covered. NB: only CONTEXT
     * values are scrubbed — never interpolate a secret into the message
     * string itself.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redactSensitive(array $context): array
    {
        foreach ($context as $key => &$value) {
            if (is_array($value)) {
                $value = self::redactSensitive($value);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $lowerKey = strtolower($key);

            // Full redaction for secret-bearing keys. Substring match:
            // over-redacting an innocent 'tokens_total' style key costs a
            // log detail; under-redacting costs a secret on disk.
            if (preg_match(
                '/api_?key|token|secret|password|passwd|signature|authorization|bearer|private_key|mnemonic|credential/',
                $lowerKey
            ) === 1) {
                $value = '***REDACTED***';
                continue;
            }

            // Wallet identifiers — FULL redaction, replaced by a keyed
            // fingerprint. See fingerprintAddress() for why this is not
            // the old first-6 + last-4 truncation.
            if (self::isWalletAddressKey($lowerKey)) {
                $value = self::fingerprintAddress($value);
            }
        }
        unset($value);

        return $context;
    }

    /**
     * Does this context key carry a member wallet address?
     *
     * Substring-matched on `wallet` / `address` so prefixed and suffixed
     * variants are covered by default — the previous exact-match list
     * (`address`, `wallet_address`, `walletaddress`) missed the key
     * `wallet`, which five call sites used, writing full addresses to
     * disk unredacted for the life of the logger.
     *
     * The exclusions are keys that contain "address" but are not member
     * wallets: `ip_address` (needed intact for abuse investigation) and
     * contract / collection / mint addresses (public on-chain
     * identifiers, and fingerprinting them would gut indexer debugging).
     */
    private static function isWalletAddressKey(string $lowerKey): bool
    {
        if (in_array($lowerKey, [
            'ip_address',
            'ip',
            'contract',
            'contract_address',
            'collection_address',
            'mint',
            'mint_address',
        ], true)) {
            return false;
        }

        if (str_contains($lowerKey, 'wallet') || str_contains($lowerKey, 'address')) {
            return true;
        }

        return in_array($lowerKey, ['addr', 'valoper', 'operator'], true);
    }

    /**
     * Replace a wallet address with a non-reversible, keyed fingerprint.
     *
     * The previous behaviour kept the first 6 and last 4 characters —
     * which IS the `address_short` form the privacy policy forbids, and
     * it was written next to `user_id` on audit lines, making the log a
     * ready-made member↔wallet table. For EVM, 10 retained hex chars is
     * ~40 bits: more than enough to confirm a candidate address.
     *
     * An HMAC keyed on the site salt keeps what logs actually need —
     * "these two lines refer to the same wallet" — while disclosing
     * nothing about which wallet it is. Reporting a privacy violation
     * must never itself commit one.
     *
     * Fails closed: with no salt available we emit a constant, not the
     * address.
     *
     * See docs/wallet-privacy-policy.md.
     */
    private static function fingerprintAddress(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $salt = function_exists('wp_salt') ? (string) wp_salt('auth') : '';
        if ($salt === '') {
            return '***WALLET_REDACTED***';
        }

        return 'wallet_fp:' . substr(hash_hmac('sha256', strtolower($value), $salt), 0, 12);
    }
}