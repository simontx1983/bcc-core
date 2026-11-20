<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoGroupRepository::countOwnedUserGroupsOrFail() — the owned-group
 * count the §21.2 custody cap compares against.
 *
 * ⚠ The lenient twin returns `int`, and `get_var()` answers null for a read
 * that did not run, which `(int)` turns into 0 — the most permissive answer
 * a cap check can receive, and indistinguishable from "owns none". The
 * strict twin answers null instead, so the caller can refuse.
 *
 * ⭐ The case that matters most is {@see testThePreFlushBailIsNullEvenWithNoLastError}:
 * `wpdb::query()` returns false on some paths BEFORE calling `flush()`, so
 * `last_result` still holds the PREVIOUS query's rows and `last_error` is
 * empty. A `last_error`-only check cannot see it, and `get_var()` would hand
 * back a count belonging to an unrelated query. That is why this reads
 * through ProbesReads rather than checking `last_error` after a `get_var()`.
 */
#[CoversClass(PeepSoGroupRepository::class)]
final class OwnedGroupCountOrFailTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            /** 'ok' | 'driver_error' | 'pre_flush_bail' */
            public string $mode = 'ok';
            /** What the COUNT returns in 'ok' mode; null = no row at all. */
            public ?string $count = '0';
            public int $queries = 0;
            public int $getVars = 0;
            /** @var list<string> */
            public array $sql = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                // Substitute nothing: the tests assert on the SHAPE, and the
                // real prepare() is not under test here.
                $this->sql[] = $sql;
                return $sql;
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries++;

                if ($this->mode === 'driver_error') {
                    // flush() ran, so last_result is reset and last_error set.
                    $this->last_result = [];
                    $this->last_error  = 'MySQL server has gone away';
                    return false;
                }

                if ($this->mode === 'pre_flush_bail') {
                    // ⚠ Returns false ABOVE flush(): last_result still holds
                    // the PREVIOUS query's rows and last_error stays EMPTY.
                    $this->last_error = '';
                    return false;
                }

                $this->last_error  = '';
                $this->last_result = $this->count === null
                    ? []
                    : [(object) ['owned_count' => $this->count]];

                return count($this->last_result);
            }

            /** The lenient twin's read path. @return string|null */
            public function get_var(string $sql): ?string
            {
                $this->getVars++;
                $ok = $this->query($sql);
                if ($ok === false) {
                    // What wpdb really does: no signal in the return value.
                    return null;
                }
                $rows = is_array($this->last_result) ? $this->last_result : [];
                if ($rows === []) {
                    return null;
                }
                return (string) $rows[0]->owned_count;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── the real answers ───────────────────────────────────────────────

    public function testACountIsReturnedAsAnInt(): void
    {
        $this->wpdb->count = '3';

        self::assertSame(3, PeepSoGroupRepository::countOwnedUserGroupsOrFail(7));
    }

    /** 0 is a REAL answer, and must not be confused with "unknown". */
    public function testZeroIsARealAnswerNotAFailure(): void
    {
        $this->wpdb->count = '0';

        self::assertSame(0, PeepSoGroupRepository::countOwnedUserGroupsOrFail(7));
    }

    public function testAnInvalidUserIdNeedsNoRead(): void
    {
        self::assertSame(0, PeepSoGroupRepository::countOwnedUserGroupsOrFail(0));
        self::assertSame(0, $this->wpdb->queries, 'no query should have run');
    }

    // ── the failures ───────────────────────────────────────────────────

    public function testADriverErrorIsNull(): void
    {
        $this->wpdb->mode = 'driver_error';

        self::assertNull(PeepSoGroupRepository::countOwnedUserGroupsOrFail(7));
    }

    /**
     * ⭐⭐ THE STALE-ROWS CASE. query() bails before flush(), so last_error
     * is EMPTY and last_result holds an unrelated query's rows. A
     * last_error-only check reads this as success and would return that
     * row's count as this user's.
     */
    public function testThePreFlushBailIsNullEvenWithNoLastError(): void
    {
        $this->wpdb->last_result = [(object) ['owned_count' => '99']];
        $this->wpdb->mode        = 'pre_flush_bail';

        self::assertNull(
            PeepSoGroupRepository::countOwnedUserGroupsOrFail(7),
            'a count from another query was returned as this user\'s'
        );
        self::assertSame('', $this->wpdb->last_error, 'the premise: no error signal was set');
    }

    /** A COUNT always returns one row, so none means the read is unusable. */
    public function testNoRowAtAllIsNullRatherThanZero(): void
    {
        $this->wpdb->count = null;

        self::assertNull(PeepSoGroupRepository::countOwnedUserGroupsOrFail(7));
    }

    // ── the contrast that motivates the method ─────────────────────────

    /**
     * ⚠ Documents WHY the strict twin exists: on the identical failure the
     * lenient one answers 0, which a cap check reads as "owns none" and
     * allows. If this ever stops being true the strict twin is redundant —
     * and if the strict twin ever starts behaving like this, the cap is open.
     */
    public function testTheLenientTwinAnswersZeroOnTheSameFailure(): void
    {
        $this->wpdb->mode = 'driver_error';

        self::assertSame(0, PeepSoGroupRepository::countOwnedUserGroups(7));
        self::assertNull(PeepSoGroupRepository::countOwnedUserGroupsOrFail(7));
    }

    // ── the shared SQL ─────────────────────────────────────────────────

    /**
     * Both twins must run the SAME query, or the strict one stops being a
     * strict reading of the same question.
     */
    public function testBothTwinsRunTheIdenticalQuery(): void
    {
        $this->wpdb->count = '1';

        PeepSoGroupRepository::countOwnedUserGroups(7);
        $lenient = $this->wpdb->sql;

        $this->wpdb->sql = [];
        PeepSoGroupRepository::countOwnedUserGroupsOrFail(7);
        $strict = $this->wpdb->sql;

        self::assertCount(1, $lenient);
        self::assertCount(1, $strict);
        self::assertSame($lenient[0], $strict[0], 'the twins have drifted apart');
    }

    /**
     * ⚠ The alias is load-bearing for the strict twin: it reads rows as
     * OBJECTS, and an unaliased `COUNT(*)` arrives as a property literally
     * named `COUNT(*)`.
     */
    public function testTheCountIsAliasedSoTheStrictTwinCanReadIt(): void
    {
        $this->wpdb->count = '2';

        PeepSoGroupRepository::countOwnedUserGroupsOrFail(7);

        self::assertStringContainsString('COUNT(*) AS owned_count', $this->wpdb->sql[0]);
    }

    /** One bounded aggregate, not a row-per-group read (§4). */
    public function testItIsOneAggregateQuery(): void
    {
        $this->wpdb->count = '5';

        PeepSoGroupRepository::countOwnedUserGroupsOrFail(7);

        self::assertSame(1, $this->wpdb->queries);
        self::assertStringContainsString("gm.gm_user_status = %s", $this->wpdb->sql[0]);
        self::assertStringContainsString('pm_kind.meta_value IS NULL', $this->wpdb->sql[0]);
    }
}
