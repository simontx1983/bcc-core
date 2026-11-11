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

        return $this->resultsToReturn;
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
