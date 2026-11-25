<?php

declare(strict_types=1);

namespace BCC\Core\Tests\Stubs;

use PDO;

/**
 * A `$wpdb` that EXECUTES on PDO (SQLite or MySQL), reads and writes: enough
 * of `prepare()` / `query()` / `last_result` / `last_error` / `insert_id`
 * for ProbesReads and PostMetaRepository's writer to run unmodified.
 *
 * - `query()` returns the row count for a SELECT and the affected rows for
 *   anything else, as wpdb does; an INSERT sets `insert_id`.
 * - `$log` keeps every statement, in order, so a test can see what was
 *   (and was not) sent.
 * - `$failOn`: a statement matching this regex fails as a driver error
 *   would (false, `last_error` set) without reaching the engine.
 * - `$insertIdOverride`: report this `insert_id` after an INSERT instead of
 *   the engine's.
 * - `$beforeQuery`: called with each statement just before it runs (after
 *   `$failOn`); a test can suspend a Fiber there to interleave two callers.
 *
 * The per-file PdoWpdb copies in the post-meta, user-meta and group-privacy
 * read tests predate this one and read only.
 */
final class PdoWpdb
{
    public string $prefix;
    public string $posts;
    public string $postmeta;
    public string $last_error = '';
    /** @var list<object> */
    public array $last_result = [];
    public int $insert_id = 0;
    /** @var list<string> */
    public array $log = [];
    public ?string $failOn = null;
    public ?int $insertIdOverride = null;
    /** @var (\Closure(string): void)|null */
    public ?\Closure $beforeQuery = null;

    public function __construct(private PDO $pdo, string $prefix)
    {
        $this->prefix   = $prefix;
        $this->posts    = $prefix . 'posts';
        $this->postmeta = $prefix . 'postmeta';
    }

    /** @param mixed ...$args */
    public function prepare(string $query, ...$args): string
    {
        $next = 0;

        return (string) preg_replace_callback(
            '/%%|%[ds]/',
            function (array $m) use (&$next, $args): string {
                if ($m[0] === '%%') {
                    return '%';
                }
                $value = $args[$next++] ?? '';

                return $m[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value);
            },
            $query
        );
    }

    /** @return int|false */
    public function query(string $sql)
    {
        $flat              = trim((string) preg_replace('/\s+/', ' ', $sql));
        $this->last_error  = '';
        $this->last_result = [];

        if ($this->failOn !== null && preg_match($this->failOn, $flat) === 1) {
            $this->log[]      = $flat;
            $this->last_error = 'injected failure';

            return false;
        }
        if ($this->beforeQuery !== null) {
            ($this->beforeQuery)($flat);
        }
        // Logged as it RUNS (after a suspension in beforeQuery), so the log
        // is the order the engine saw.
        $this->log[] = $flat;

        try {
            $statement = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
        if ($statement === false) {
            $this->last_error = 'statement failed';

            return false;
        }
        if ($statement->columnCount() > 0) {
            $this->last_result = $statement->fetchAll(PDO::FETCH_OBJ);

            return count($this->last_result);
        }
        if (stripos($flat, 'INSERT') === 0) {
            $this->insert_id = $this->insertIdOverride ?? (int) $this->pdo->lastInsertId();
        }

        return $statement->rowCount();
    }
}
