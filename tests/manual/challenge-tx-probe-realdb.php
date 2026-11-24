<?php
/**
 * MANUAL check, never run by CI (PHPUnit collects only *Test.php files):
 * ChallengeRepository's transaction probe, consume() and
 * consumeInTransaction() against a REAL MySQL 8 or MariaDB server.
 *
 * It goes through a wpdb-shaped adapter that exposes its mysqli link as
 * `dbh`, as WordPress's wpdb does, so the probe reads the error NUMBER
 * (1305), the path production takes. Run it once with the server's default
 * messages and once with --lc-messages=de_DE: the answer must not depend on
 * the language of the error text.
 *
 * It creates ONE database with a random name (bcc_txprobe_<hex>), works only
 * inside it, and drops it at the end. The user needs CREATE and DROP on the
 * server; nothing else is read or written.
 *
 * Usage, from the bcc-core root:
 *   BCC_PROBE_DB_PASSWORD=... php tests/manual/challenge-tx-probe-realdb.php \
 *       --port=3306 [--host=127.0.0.1] [--user=root] [--lc-messages=de_DE]
 *
 * Exit 0: every check passed. 1: a check failed. 2: it could not run.
 */

declare(strict_types=1);

// CLI only: a web request for this file must never run it.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "needs the mysqli extension: php -d extension=mysqli tests/manual/challenge-tx-probe-realdb.php ...\n");
    exit(2);
}

$opt  = getopt('', ['host:', 'port:', 'user:', 'lc-messages:']);
$pass = getenv('BCC_PROBE_DB_PASSWORD');
if (!is_array($opt) || !isset($opt['port']) || $pass === false) {
    fwrite(STDERR, "usage: BCC_PROBE_DB_PASSWORD=... php tests/manual/challenge-tx-probe-realdb.php --port=N [--host=H] [--user=U] [--lc-messages=de_DE]\n");
    exit(2);
}
$host = (string) ($opt['host'] ?? '127.0.0.1');
$user = (string) ($opt['user'] ?? 'root');
$lc   = (string) ($opt['lc-messages'] ?? '');

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The WordPress functions ChallengeRepository and DegradationMetrics call.
if (!function_exists('maybe_serialize')) {
    /** @param mixed $d */
    function maybe_serialize($d): string
    {
        return is_array($d) || is_object($d) ? serialize($d) : (string) $d;
    }
}
if (!function_exists('maybe_unserialize')) {
    /** @return mixed */
    function maybe_unserialize(string $d)
    {
        $v = @unserialize($d);
        return $v === false && $d !== 'b:0;' ? $d : $v;
    }
}
if (!function_exists('wp_cache_delete')) {
    /** @param int|string $k */
    function wp_cache_delete($k, string $g = ''): bool
    {
        return true;
    }
}
if (!function_exists('get_transient')) {
    /** @return mixed */
    function get_transient(string $k)
    {
        return $GLOBALS['__bcc_probe_transients'][$k] ?? false;
    }
}
if (!function_exists('set_transient')) {
    /** @param mixed $v */
    function set_transient(string $k, $v, int $ttl = 0): bool
    {
        $GLOBALS['__bcc_probe_transients'][$k] = $v;
        return true;
    }
}

/** The parts of wpdb ChallengeRepository uses, over a real mysqli link. */
final class BccProbeAdapterWpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var list<object> */
    public array $last_result = [];
    /** wpdb exposes its link the same way (a protected property behind __get). */
    public \mysqli $dbh;
    private bool $suppress = false;

    public function __construct(\mysqli $dbh)
    {
        $this->dbh = $dbh;
    }

    public function suppress_errors(bool $s = true): bool
    {
        $was            = $this->suppress;
        $this->suppress = $s;
        return $was;
    }

    /** @param mixed ...$args */
    public function prepare(string $q, ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[sd]/', function (array $m) use (&$i, $args): string {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . $this->dbh->real_escape_string((string) $v) . "'";
        }, $q);
    }

    /** @return int|bool */
    public function query(string $sql)
    {
        $this->last_error  = '';
        $this->last_result = [];
        $r = $this->dbh->query($sql);
        if ($r === false) {
            $this->last_error = $this->dbh->error;
            return false;
        }
        if ($r === true) {
            return $this->dbh->affected_rows;
        }
        while ($row = $r->fetch_object()) {
            $this->last_result[] = $row;
        }
        return count($this->last_result);
    }

    public function get_var(string $sql): ?string
    {
        if ($this->query($sql) === false || $this->last_result === []) {
            return null;
        }
        $v = array_values((array) $this->last_result[0])[0] ?? null;
        return $v === null ? null : (string) $v;
    }
}

use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\Wallet\ChallengeRepository as R;

mysqli_report(MYSQLI_REPORT_OFF);
$m = @new \mysqli($host, $user, $pass, '', (int) $opt['port']);
if ($m->connect_errno !== 0) {
    fwrite(STDERR, "cannot connect: {$m->connect_error}\n");
    exit(2);
}
$db = 'bcc_txprobe_' . bin2hex(random_bytes(6));
if ($m->query("CREATE DATABASE `{$db}`") === false) {
    fwrite(STDERR, "cannot create the scratch database: {$m->error}\n");
    exit(2);
}

// Dropped however the run ends: exit() skips `finally`, a shutdown function
// still runs (also after a fatal error).
register_shutdown_function(static function () use ($m, $db): void {
    @$m->query('ROLLBACK');
    @$m->query('SET autocommit = 1');
    $m->query("DROP DATABASE IF EXISTS `{$db}`");
});

$ok = 0;
$n  = 0;
{
    $m->select_db($db);
    $m->query("CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB");
    if ($lc !== '' && $m->query("SET SESSION lc_messages = '" . $m->real_escape_string($lc) . "'") === false) {
        fwrite(STDERR, "cannot set lc_messages: {$m->error}\n");
        exit(2);
    }
    $GLOBALS['wpdb'] = new BccProbeAdapterWpdb($m);

    $m->query('SAVEPOINT bcc_show');
    $m->query('RELEASE SAVEPOINT bcc_show');
    printf("== %s, lc_messages=%s\n   the server's RELEASE-outside error: errno %d, \"%s\"\n", $m->server_info, $lc === '' ? 'default' : $lc, $m->errno, $m->error);

    $check = static function (string $what, bool $pass) use (&$ok, &$n): void {
        $n++;
        $ok += $pass ? 1 : 0;
        printf("   %-74s %s\n", $what, $pass ? 'ok' : 'FAIL');
    };
    $write = static function (string $name) use ($m): bool {
        return $m->query("INSERT INTO wp_options (option_name, option_value) VALUES ('{$name}', 'x')") === true;
    };
    $exists = static function (string $name) use ($m): bool {
        $r = $m->query("SELECT 1 FROM wp_options WHERE option_name = '{$name}'");
        return $r instanceof \mysqli_result && $r->num_rows === 1;
    };
    $state = static fn (): string => R::transactionState();

    // ── the probe ───────────────────────────────────────────────────────
    $check('autocommit, nothing open -> outside', $state() === R::TX_OUTSIDE);

    $m->query('START TRANSACTION');
    $check('START TRANSACTION, no statement yet -> inside', $state() === R::TX_INSIDE);
    $m->query('ROLLBACK');

    $m->query('START TRANSACTION');
    $check('START TRANSACTION + a write (INSERT) -> inside', $write('w_commit') && $state() === R::TX_INSIDE);
    $check('a second probe in the same transaction -> inside', $state() === R::TX_INSIDE);
    $m->query('COMMIT');
    $check('after COMMIT -> outside', $state() === R::TX_OUTSIDE);
    $check('the probed transaction\'s write was committed by COMMIT', $exists('w_commit'));

    $m->query('START TRANSACTION');
    $wrote = $write('w_rollback');
    $check('START TRANSACTION + a write, again -> inside', $wrote && $state() === R::TX_INSIDE);
    $m->query('ROLLBACK');
    $check('after ROLLBACK -> outside', $state() === R::TX_OUTSIDE);
    $check('the probe committed nothing: the rolled-back write is absent', !$exists('w_rollback'));

    $m->query('START TRANSACTION');
    $m->query("SELECT option_value FROM wp_options WHERE option_name = 'w_commit' FOR UPDATE");
    $check('START TRANSACTION + a locking read only (FOR UPDATE) -> inside', $state() === R::TX_INSIDE);
    $m->query('ROLLBACK');

    $m->query('START TRANSACTION');
    $write('w_dup');
    $dupFailed = $m->query("INSERT INTO wp_options (option_name, option_value) VALUES ('w_dup', 'y')") === false && $m->errno === 1062;
    $check('a statement error (1062) inside a transaction -> still inside', $dupFailed && $state() === R::TX_INSIDE);
    $m->query('ROLLBACK');
    $check('after that ROLLBACK -> outside, and its write is absent', $state() === R::TX_OUTSIDE && !$exists('w_dup'));

    $m->query('SET autocommit = 0');
    $check('autocommit = 0 + a write -> inside', $write('w_ac0') && $state() === R::TX_INSIDE);
    $m->query('ROLLBACK');
    $m->query('SET autocommit = 1');
    $check('autocommit back on -> outside, and the write is absent', $state() === R::TX_OUTSIDE && !$exists('w_ac0'));

    // ── consume() and consumeInTransaction() on top of it ───────────────
    R::store('k1', ['nonce' => 'n1', 'expires_at' => time() + 600], 600);
    $check('consume() outside a transaction takes the challenge', (R::consume('k1')['nonce'] ?? null) === 'n1');

    R::store('k2', ['nonce' => 'n2', 'expires_at' => time() + 600], 600);
    $m->query('START TRANSACTION');
    $write('w_caller');
    $threw = false;
    try {
        R::consume('k2');
    } catch (\LogicException $e) {
        $threw = true;
    }
    $check('consume() after a caller\'s write inside a transaction is refused', $threw);
    $m->query('ROLLBACK');
    $check('...and the caller\'s ROLLBACK still removes its write (nothing committed it)', !$exists('w_caller'));
    $check('...and the challenge is still there', R::peek('k2') !== null);

    $m->query('START TRANSACTION');
    $write('w_claim');
    $check('consumeInTransaction() after a write inside a transaction takes it', (R::consumeInTransaction('k2')['nonce'] ?? null) === 'n2');
    $m->query('ROLLBACK');
    $check('...and the caller\'s ROLLBACK restores the challenge and drops the write', R::peek('k2') !== null && !$exists('w_claim'));

    $threw = false;
    try {
        R::consumeInTransaction('k2');
    } catch (\LogicException $e) {
        $threw = true;
    }
    $check('consumeInTransaction() outside a transaction is refused', $threw);

    $check('no wallet_challenge/tx_probe_unknown recorded in the whole run', DegradationMetrics::readEvent('wallet_challenge', 'tx_probe_unknown', time()) === 0);
}

echo "   {$ok}/{$n} ok\n";
exit($n > 0 && $ok === $n ? 0 : 1);
