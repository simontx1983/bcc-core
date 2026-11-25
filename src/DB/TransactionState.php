<?php

namespace BCC\Core\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is a transaction open on this connection? Asked of the connection itself,
 * never of a PHP counter, so a transaction opened anywhere (a direct
 * START TRANSACTION, BEGIN, autocommit off) is seen.
 *
 * The one shared probe. Code that must start its own transaction asks it
 * first and starts only on OUTSIDE; code that must run inside one proceeds
 * only on INSIDE. UNKNOWN answers neither, and every caller refuses on it.
 *
 * It records no metric of its own: each caller records under its own
 * subsystem (Wallet\ChallengeRepository: `wallet_challenge/tx_probe_unknown`;
 * bcc-trust's transaction guard: `db_transaction/*`), so a count says which
 * path refused.
 */
final class TransactionState
{
    use \BCC\Core\Repositories\ProbesReads;

    /** An explicit or implicit transaction is open. */
    public const INSIDE = 'inside';

    /** No transaction is open. */
    public const OUTSIDE = 'outside';

    /** The probe failed in a way that answers neither. */
    public const UNKNOWN = 'unknown';

    /**
     * A probe that works the same on MySQL 8 and MariaDB: SAVEPOINT, then
     * RELEASE SAVEPOINT. Inside a transaction (START TRANSACTION, BEGIN, or
     * autocommit off) the release succeeds. Outside one the savepoint ends
     * with its implicit transaction, so the release fails with error 1305
     * "SAVEPOINT … does not exist".
     *
     * ⚠ Any OTHER failure is UNKNOWN, never OUTSIDE, and every caller
     * refuses on it.
     * ⚠ The savepoint name is unique per call: a savepoint with a caller's
     * name (bcc-trust's TransactionManager uses sp_<depth>_<rand>) would
     * silently replace theirs.
     *
     * (INFORMATION_SCHEMA.INNODB_TRX cannot answer this on MySQL 8: the view
     * is served from a cache refreshed at most every ~100 ms, and a
     * transaction is not listed until it first touches InnoDB.)
     *
     * Errors are suppressed for the probe and `last_error` is cleared after
     * it, so an expected 1305 is neither logged nor mistaken later for a
     * failure of the next query.
     *
     * ⚠ "Outside" is recognised by the error NUMBER (1305,
     * ER_SP_DOES_NOT_EXIST), read from the connection, never by the message
     * alone. A server set to another language (lc_messages) words the
     * message differently, and a message-only match would answer UNKNOWN on
     * every call, so every caller would refuse. The English message is
     * matched only when the error number cannot be read (no mysqli link).
     *
     * @return self::INSIDE|self::OUTSIDE|self::UNKNOWN
     */
    public static function current(): string
    {
        global $wpdb;

        $name       = 'bcc_txprobe_' . bin2hex(random_bytes(8));
        $suppressed = $wpdb->suppress_errors(true);
        try {
            self::clearLastError();
            $state = self::probe($name);
        } finally {
            self::clearLastError();
            $wpdb->suppress_errors($suppressed);
        }
        return $state;
    }

    /** MySQL / MariaDB ER_SP_DOES_NOT_EXIST: "SAVEPOINT … does not exist". */
    private const ER_SP_DOES_NOT_EXIST = 1305;

    /**
     * One SAVEPOINT / RELEASE round on the connection (see current()).
     *
     * @return self::INSIDE|self::OUTSIDE|self::UNKNOWN
     */
    private static function probe(string $name): string
    {
        global $wpdb;

        if ($wpdb->query('SAVEPOINT ' . $name) === false) {
            return self::UNKNOWN;
        }
        if ($wpdb->query('RELEASE SAVEPOINT ' . $name) !== false) {
            return self::INSIDE;
        }
        $errno = self::lastErrorNumber();
        if ($errno === self::ER_SP_DOES_NOT_EXIST) {
            return self::OUTSIDE;
        }
        if ($errno === 0 && self::lastErrorMessage() === 'SAVEPOINT ' . $name . ' does not exist') {
            return self::OUTSIDE; // no error number to read: the English message, as a fallback
        }
        return self::UNKNOWN;
    }

    /**
     * The connection's last error number (wpdb's mysqli link), or 0 when it
     * cannot be read: no link, or a link that has gone away.
     */
    private static function lastErrorNumber(): int
    {
        global $wpdb;

        $dbh = isset($wpdb->dbh) ? $wpdb->dbh : null;
        if (!$dbh instanceof \mysqli) {
            return 0;
        }
        try {
            return (int) mysqli_errno($dbh);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The driver's last error message (a helper, for the reason ProbesReads::clearLastError() gives). */
    private static function lastErrorMessage(): string
    {
        global $wpdb;
        return (string) $wpdb->last_error;
    }
}
