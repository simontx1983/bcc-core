<?php

declare(strict_types=1);

namespace BCC\Core\Tests\Stubs;

use PHPUnit\Framework\TestCase;

/**
 * `$wpdb` double that renders each placeholder with its bound argument, so
 * a test reads the statement MySQL would receive. Asserting on placeholders
 * and arguments separately cannot see a JOIN param bound to a WHERE slot,
 * and that misordering is what the activity-query scopes are most exposed to.
 *
 * Extracted from ActivityPageScopeTest (where it was first written) so the
 * restricted-group exclusion suite can share it instead of carrying a second
 * copy — §11 reuse. The behaviour is unchanged from that original.
 */
final class RenderingWpdb
{
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $prefix   = 'wp_';

    public string $lastSql = '';

    public int $queryCount = 0;

    /** @var list<object> */
    public array $resultsToReturn = [];

    /** @param mixed ...$args */
    public function prepare(string $query, ...$args): string
    {
        $next = 0;
        $sql  = preg_replace_callback(
            '/%[ds]/',
            static function (array $m) use (&$next, $args): string {
                $value = $args[$next++] ?? '<<UNBOUND>>';

                return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
            },
            $query
        );

        TestCase::assertSame(count($args), $next, 'every bound argument must have a placeholder');

        return (string) preg_replace('/\s+/', ' ', (string) $sql);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * @param string $sql
     * @return list<object>
     */
    public function get_results($sql): array
    {
        $this->lastSql = $sql;
        ++$this->queryCount;

        // F-275: a raised driver error reads as `[]`, exactly as real
        // `get_results()` hands it back. That indistinguishability is the
        // defect; '' (the default) leaves every existing test unchanged.
        if ($this->errorOnQuery !== '') {
            $this->last_error = $this->errorOnQuery;
            return [];
        }

        return $this->resultsToReturn;
    }

    // ── F-275: the trusted read path (ProbesReads::trustedRows) ─────────
    // Additive. Modelled on BlockedSender\CaptureWpdb, which carries the
    // full reasoning for each case.

    /** Real `wpdb::$last_error`; production clears it before a trusted read. */
    public string $last_error = '';

    /** A driver error the NEXT read raises, or '' for success. */
    public string $errorOnQuery = '';

    /**
     * Forces `query()` to return false WITHOUT an error, which models
     * real wpdb's pre-`flush()` bails (a failed reconnect, an empty
     * `query` filter result): no `last_error`, and `last_result` still
     * holds the PREVIOUS query's rows.
     */
    public bool $bailBeforeFlush = false;

    /** @var list<object> Real `wpdb::$last_result`. */
    public array $last_result = [];

    /** @var list<string> `last_error` as seen when each `query()` began. */
    public array $errorSeenAtQuery = [];

    /**
     * Real `wpdb::query()`: the ROW COUNT for a SELECT (so a genuine empty
     * result is 0, not false), or false on failure. Rows are left alone on
     * either failure, as the real pre-flush bail leaves them.
     *
     * @return int|false
     */
    public function query(string $sql)
    {
        $this->lastSql = $sql;
        ++$this->queryCount;
        $this->errorSeenAtQuery[] = $this->last_error;

        if ($this->errorOnQuery !== '') {
            $this->last_error = $this->errorOnQuery;
            return false;
        }
        if ($this->bailBeforeFlush) {
            return false;
        }

        $this->last_result = $this->resultsToReturn;

        return count($this->resultsToReturn);
    }

    /** @var object|null */
    public $rowToReturn = null;

    /**
     * Single-row read, for the callers that use it (`getById`). Records
     * the statement exactly as `get_results()` does, so a test can assert
     * the rendered SQL of a single-post query.
     *
     * @param string $sql
     * @return object|null
     */
    public function get_row($sql)
    {
        $this->lastSql = $sql;
        ++$this->queryCount;

        // F-275: a raised driver error reads as null, as real `get_row()`
        // hands it back — the same answer as "no such row".
        if ($this->errorOnQuery !== '') {
            $this->last_error = $this->errorOnQuery;
            return null;
        }

        return $this->rowToReturn;
    }

    /** @var list<int|string> */
    public array $colToReturn = [];

    /** @var int|string|null */
    public $varToReturn = 0;

    /**
     * Additive, for repositories that read a single column rather than
     * rows — the follower / following id reads. Records the statement
     * exactly as `get_results` does, so the same rendering guarantee,
     * and the placeholder/argument count assertion in `prepare`, cover
     * those reads too.
     *
     * @param string $sql
     * @return list<int|string>
     */
    public function get_col($sql): array
    {
        $this->lastSql = $sql;
        ++$this->queryCount;

        return $this->colToReturn;
    }

    /**
     * Additive, for COUNT reads.
     *
     * ⚠ `lastSql` holds the LAST statement a method issued. A method
     * running two counts — `PeepSoFollowerRepository::getCounts()` does —
     * therefore leaves the SECOND one here, not the first. A test reading
     * `lastSql` after such a call must say which one it means, or it is
     * asserting about a statement it did not choose.
     *
     * @param string $sql
     * @return int|string|null
     */
    public function get_var($sql)
    {
        $this->lastSql = $sql;
        ++$this->queryCount;

        return $this->varToReturn;
    }
}
