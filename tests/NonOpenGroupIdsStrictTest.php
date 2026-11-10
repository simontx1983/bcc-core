<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\NonOpenStrict;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `$wpdb` double that models REAL WPDB failure behaviour.
 *
 * ⚠ The previous version of this double declared `get_col(): ?array` and
 * returned null to mean "the query failed". Real WPDB never does that, and
 * the double's lie is what let a broken `=== null` failure check pass its
 * own tests. wp-includes/class-wpdb.php:3114-3121 builds
 * `$new_array = array()`, fills it only `if ($this->last_result)`, and
 * returns it unconditionally — so an errored query yields `[]`, identical
 * to a legitimately empty result.
 *
 * This double therefore reproduces what WPDB actually does on error:
 *
 *   - `query()` returns FALSE (documented `int|bool`, "false on error");
 *     on success it returns the row count, which is 0 for an empty result
 *     and must NOT be mistaken for failure.
 *   - `last_error` is set to a non-empty string, and `query()` clears it
 *     first (real WPDB clears it in flush()).
 *   - `get_col()` returns an ARRAY, always — `[]` after a failure.
 *
 * Any failure detection that works against this double works against
 * WordPress.
 */
final class ColWpdb
{
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $prefix   = 'wp_';

    public string $last_error = '';

    public int $queryCount = 0;

    /** Rows the next successful query yields. */
    public array $next = [];

    /** When true, the next query ERRORS the way WPDB errors. */
    public bool $fail = false;

    /** @var list<string> */
    private array $lastResult = [];

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

        return (string) preg_replace('/\s+/', ' ', (string) $sql);
    }

    /**
     * @param string $sql
     * @return int|false row count, or false on error — exactly as WPDB
     */
    public function query($sql)
    {
        ++$this->queryCount;

        // flush(): last_error and last_result are cleared first.
        $this->last_error = '';
        $this->lastResult = [];

        if ($this->fail) {
            $this->last_error = 'WordPress database error: simulated failure';

            return false;
        }

        $this->lastResult = array_values($this->next);

        return count($this->lastResult);
    }

    /**
     * ALWAYS an array, like the real thing. With a null $sql it reads the
     * already-fetched last_result without re-querying.
     *
     * @param string|null $sql
     * @return list<string>
     */
    public function get_col($sql = null): array
    {
        if ($sql !== null && $sql !== '') {
            $this->query($sql);
        }

        return $this->lastResult;
    }
}

/**
 * The non-open group list is the deny list a group-visibility gate is
 * built on, so its FAILURE MODES are security behaviour, not ergonomics.
 *
 * Two pins here:
 *
 *   1. getNonOpenGroupIds must not CACHE a failed query. It flattens a
 *      null get_col into [] for the caller (its historical behaviour),
 *      but caching that [] would hold "no group is non-open" for the
 *      10-minute TTL — and for a caller using the list as an exclusion,
 *      an empty list means "exclude nothing". One transient DB error
 *      would read as permission for ten minutes.
 *   2. getNonOpenGroupIdsStrict must return NULL rather than a partial
 *      or empty-looking answer, for both failure and truncation, so a
 *      caller that uses the list as its ONLY gate can fail closed.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'getNonOpenGroupIds')]
#[CoversMethod(PeepSoGroupRepository::class, 'getNonOpenGroupIdsStrict')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NonOpenGroupIdsStrictTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/object-cache-stubs.php';
        \BccTestPersistentCache::reset();
    }

    /** @param list<string> $next */
    private function installWpdb(array $next, bool $fail = false): ColWpdb
    {
        $wpdb            = new ColWpdb();
        $wpdb->next      = $next;
        $wpdb->fail      = $fail;
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    // ── 1. a failed query is not cached ──────────────────────────────

    /**
     * THE REGRESSION PIN. A failed lookup followed by a GOOD one must
     * return the good answer. If the failure were cached, the second
     * call would be served [] from cache and never reach the database —
     * which is exactly what made a transient error look like
     * "nothing is restricted" for ten minutes.
     */
    public function testAFailedLookupIsNotCachedAndTheNextCallReQueries(): void
    {
        $wpdb = $this->installWpdb([], true); // query ERRORS like WPDB does

        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIds(500));
        self::assertSame(1, $wpdb->queryCount);

        // Same process, same cache: the recovered query must be consulted.
        $wpdb->fail = false;
        $wpdb->next = ['7', '9'];

        self::assertSame([7, 9], PeepSoGroupRepository::getNonOpenGroupIds(500));
        self::assertSame(2, $wpdb->queryCount, 'a cached failure would have skipped this query');
    }

    /**
     * A genuinely EMPTY result is still cached — that is a real answer
     * and the caching behaviour must not regress for it.
     */
    public function testAGenuinelyEmptyResultIsStillCached(): void
    {
        $wpdb = $this->installWpdb([]);

        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIds(500));
        self::assertSame(1, $wpdb->queryCount);

        $wpdb->next = ['7'];

        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIds(500), 'served from cache');
        self::assertSame(1, $wpdb->queryCount, 'an empty answer is cacheable');
    }

    // ── 2. the strict variant ────────────────────────────────────────

    public function testStrictReturnsNullOnAFailedQuery(): void
    {
        $this->installWpdb([], true);

        self::assertNull(PeepSoGroupRepository::getNonOpenGroupIdsStrict(500));
    }

    /**
     * Truncation is detected by asking for limit+1 and seeing the extra
     * row, not by guessing from a full-looking page. The underlying query
     * orders by ID DESC, so a truncated list drops the OLDEST non-open
     * groups — established private communities — and under-restricting
     * is the leaking direction.
     */
    public function testStrictReturnsNullWhenMoreRowsExistThanTheLimit(): void
    {
        $limit = 5;
        // limit + 1 rows come back ⇒ row 6 is the detector.
        $this->installWpdb(array_map('strval', range(1, $limit + 1)));

        self::assertNull(PeepSoGroupRepository::getNonOpenGroupIdsStrict($limit));
    }

    public function testStrictReturnsTheListWhenExactlyAtTheLimit(): void
    {
        $limit = 5;
        $this->installWpdb(array_map('strval', range(1, $limit)));

        self::assertSame([1, 2, 3, 4, 5], PeepSoGroupRepository::getNonOpenGroupIdsStrict($limit));
    }

    /**
     * An empty strict answer is TRUSTWORTHY: the query ran and no group
     * is non-open. That is what lets a caller treat [] as "restricted
     * from nothing" instead of "I do not know".
     */
    public function testStrictEmptyMeansTheQueryRanAndFoundNothing(): void
    {
        $this->installWpdb([]);

        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIdsStrict(500));
    }

    public function testStrictDoesNotCacheAFailure(): void
    {
        $wpdb = $this->installWpdb([], true);

        self::assertNull(PeepSoGroupRepository::getNonOpenGroupIdsStrict(500));
        self::assertSame(1, $wpdb->queryCount);

        $wpdb->fail = false;
        $wpdb->next = ['7'];

        self::assertSame([7], PeepSoGroupRepository::getNonOpenGroupIdsStrict(500));
        self::assertSame(2, $wpdb->queryCount, 'a cached failure would have skipped this query');
    }

    public function testStrictDoesNotCacheATruncatedAnswer(): void
    {
        $limit = 3;
        $wpdb  = $this->installWpdb(array_map('strval', range(1, $limit + 1)));

        self::assertNull(PeepSoGroupRepository::getNonOpenGroupIdsStrict($limit));

        $wpdb->next = ['1', '2'];

        self::assertSame([1, 2], PeepSoGroupRepository::getNonOpenGroupIdsStrict($limit));
        self::assertSame(2, $wpdb->queryCount, 'a truncated answer must never be served again');
    }

    public function testStrictRefusesANonPositiveLimit(): void
    {
        $wpdb = $this->installWpdb(['7']);

        self::assertNull(PeepSoGroupRepository::getNonOpenGroupIdsStrict(0));
        self::assertSame(0, $wpdb->queryCount);
    }

    /**
     * The strict and lenient answers live under DIFFERENT cache keys. A
     * lenient [] (which may be a flattened failure) must never be served
     * to a strict caller as a proven-empty answer.
     */
    public function testStrictAndLenientDoNotShareACacheEntry(): void
    {
        $wpdb = $this->installWpdb([]);

        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIds(500));
        self::assertSame(1, $wpdb->queryCount);

        // Strict must run its OWN query rather than reading the lenient
        // entry — it needs the limit+1 probe to know about truncation.
        self::assertSame([], PeepSoGroupRepository::getNonOpenGroupIdsStrict(500));
        self::assertSame(2, $wpdb->queryCount);
    }
}
