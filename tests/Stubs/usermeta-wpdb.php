<?php
/**
 * `$wpdb` double for UserMetaRepository's read.
 *
 * - The usermeta SELECT is answered from a fixture in `$GLOBALS[$metaGlobal]`
 *   (user => key => value or list of values, in umeta_id order), honouring
 *   the query's user ids, keys and LIMIT. Keys match exactly.
 * - `$modes` scripts each usermeta query in turn: 'ok' (the default),
 *   'error' (flush, then last_error, query() false) or 'preflush' (query()
 *   false BEFORE flush: no last_error, and last_result still holds the
 *   PREVIOUS query's rows, as wpdb's `! $this->ready` guard or an emptied
 *   `query` filter leaves them).
 * - `$scriptedRows` replaces the fixture with exact rows (a user nobody
 *   asked for, a case-variant key).
 * - Any other statement (the follow graph) finds nothing.
 *
 * get_results() / get_col() / get_var() call query() and DISCARD its return
 * value, then read last_result, as real wpdb does, so a reader built on
 * them sees the stale rows in 'preflush' mode.
 */

declare(strict_types=1);

namespace BCC\Core\Tests\Stubs;

final class UserMetaWpdb
{
    public string $prefix   = 'wp_';
    public string $usermeta = 'wp_usermeta';
    public string $users    = 'wp_users';
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    public string $last_error = '';
    /** @var list<object> */
    public array $last_result = [];

    /** @var list<string> */
    public array $modes = [];

    /** @var list<object>|null */
    public ?array $scriptedRows = null;

    public int $metaQueries = 0;

    public function __construct(public string $metaGlobal = '__um_meta')
    {
    }

    /** @param mixed ...$args */
    public function prepare(string $query, ...$args): string
    {
        $next = 0;

        return (string) preg_replace_callback(
            '/%[ds]/',
            static function (array $m) use (&$next, $args): string {
                $value = $args[$next++] ?? '';

                return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
            },
            $query
        );
    }

    /** @return int|false */
    public function query(string $sql)
    {
        if (!str_contains($sql, 'FROM ' . $this->usermeta)) {
            $this->last_error  = '';
            $this->last_result = [];

            return 0;
        }

        $this->metaQueries++;
        $mode = array_shift($this->modes) ?? 'ok';
        if ($mode === 'preflush') {
            return false;
        }
        $this->last_error  = '';
        $this->last_result = [];
        if ($mode === 'error') {
            $this->last_error = 'Lock wait timeout exceeded; try restarting transaction';

            return false;
        }

        if ($this->scriptedRows !== null) {
            $this->last_result = $this->scriptedRows;

            return count($this->last_result);
        }

        $ids = [];
        if (preg_match('/user_id IN \(([^)]*)\)/', $sql, $m) === 1) {
            foreach (explode(',', $m[1]) as $id) {
                $ids[(int) trim($id)] = true;
            }
        }
        $keys = [];
        if (preg_match('/meta_key IN \(([^)]*)\)/', $sql, $m) === 1) {
            foreach (explode("', '", trim($m[1], "'")) as $key) {
                $keys[stripslashes($key)] = true;
            }
        }
        $limit = preg_match('/LIMIT (\d+)/', $sql, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;

        $rows = [];
        foreach ((array) ($GLOBALS[$this->metaGlobal] ?? []) as $userId => $byKey) {
            if (!isset($ids[(int) $userId])) {
                continue;
            }
            foreach ((array) $byKey as $key => $values) {
                if (!isset($keys[(string) $key])) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    $rows[] = (object) ['user_id' => (string) $userId, 'meta_key' => (string) $key, 'meta_value' => (string) $value];
                }
            }
        }
        $this->last_result = array_slice($rows, 0, $limit);

        return count($this->last_result);
    }

    /** @return list<object> */
    public function get_results(?string $sql = null): array
    {
        if ($sql !== null) {
            $this->query($sql);
        }

        return $this->last_result;
    }

    /** @return list<string> */
    public function get_col(?string $sql = null): array
    {
        if ($sql !== null) {
            $this->query($sql);
        }

        return array_map(static fn (object $r): string => (string) (array_values(get_object_vars($r))[0] ?? ''), $this->last_result);
    }

    /** @return mixed */
    public function get_var(?string $sql = null)
    {
        $col = $this->get_col($sql);

        return $col[0] ?? null;
    }

    public function get_row(?string $sql = null): ?object
    {
        if ($sql !== null) {
            $this->query($sql);
        }

        return $this->last_result[0] ?? null;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
}
