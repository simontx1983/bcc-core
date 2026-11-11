<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\MembershipStatus;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * `$wpdb` double that renders each placeholder with its bound argument, so
 * the test reads the statement MySQL would receive.
 *
 * ⚠ `get_results` models the REAL contract, which is not the same as
 * `get_col`'s: it returns null only when the QUERY STRING is empty, and
 * the (post-flush, empty) `last_result` on a query ERROR. It never signals
 * an error by returning null. A double that pretended otherwise is exactly
 * how a dead failure check passed its own tests earlier in this work.
 */
final class MembershipWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public string $lastSql = '';

    /** Rows the next query yields. @var list<object> */
    public array $rows = [];

    /** When true, the query ERRORS the way WPDB errors. */
    public bool $fail = false;

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

    /**
     * @param string $sql
     * @return list<object>
     */
    public function get_results($sql): array
    {
        $this->lastSql    = $sql;
        $this->last_error = '';

        if ($this->fail) {
            $this->last_error = 'WordPress database error: simulated failure';

            // An errored query leaves last_result empty, so callers get [].
            return [];
        }

        return $this->rows;
    }
}

/**
 * `findUserMemberships` now also returns the membership ROLE.
 *
 * The reason is a one-value gap that matters: the method's own filter is
 * `gm_user_status LIKE 'member%'`, which ADMITS `member_readonly`, while
 * the set allowed to author a post excludes it. So row presence answers
 * "is a member" correctly and "may post" INCORRECTLY — it says yes to a
 * read-only member. Carrying the status makes the posting decision one
 * batched query instead of one per group, which is what kept the profile
 * Groups listing from becoming an N+1.
 *
 * The change is additive, and these tests pin that: the pre-existing
 * fields keep their names and values, and the only difference is one more
 * column.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'findUserMemberships')]
final class FindUserMembershipsStatusTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
    }

    private function installWpdb(): MembershipWpdb
    {
        $wpdb            = new MembershipWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    /** @param array<string, string> $fields */
    private static function row(array $fields): object
    {
        return (object) $fields;
    }

    /**
     * FAILS IF THE COLUMN IS NOT SELECTED. Without it the status is absent
     * from every row and a posting decision silently falls back to
     * presence, which over-reports.
     */
    public function testTheStatusColumnIsSelected(): void
    {
        $wpdb = $this->installWpdb();

        PeepSoGroupRepository::findUserMemberships(7, [3]);

        self::assertStringContainsString('gm_user_status AS user_status', $wpdb->lastSql);
    }

    public function testTheStatusIsReturnedPerGroup(): void
    {
        $wpdb       = $this->installWpdb();
        $wpdb->rows = [
            self::row([
                'group_id'      => '3',
                'joined_at'     => '2026-01-01 00:00:00',
                'gm_joined_utc' => '1767225600',
                'user_status'   => 'member_owner',
            ]),
            self::row([
                'group_id'      => '4',
                'joined_at'     => '2026-02-01 00:00:00',
                'gm_joined_utc' => '1769904000',
                'user_status'   => 'member_readonly',
            ]),
        ];

        $out = PeepSoGroupRepository::findUserMemberships(7, [3, 4]);

        self::assertSame('member_owner', $out[3]->user_status);
        self::assertSame('member_readonly', $out[4]->user_status);
    }

    /**
     * THE POINT OF THE CHANGE. Both rows are "members" by the method's own
     * filter, so presence cannot separate them — only the status can. This
     * is the distinction a picker's can_post_blog depends on.
     */
    public function testPresenceCannotSeparateAReadOnlyMemberButTheStatusCan(): void
    {
        $wpdb       = $this->installWpdb();
        $wpdb->rows = [
            self::row(['group_id' => '3', 'joined_at' => 'x', 'gm_joined_utc' => '1', 'user_status' => 'member']),
            self::row(['group_id' => '4', 'joined_at' => 'x', 'gm_joined_utc' => '1', 'user_status' => 'member_readonly']),
        ];

        $out = PeepSoGroupRepository::findUserMemberships(7, [3, 4]);

        // Presence says yes to both — which is why presence is the wrong
        // question for "may post".
        self::assertArrayHasKey(3, $out);
        self::assertArrayHasKey(4, $out);

        // The status separates them.
        self::assertNotSame($out[3]->user_status, $out[4]->user_status);
    }

    /**
     * ADDITIVE: the fields every existing caller reads are untouched. The
     * six call sites in bcc-trust use row PRESENCE or `joined_at` /
     * `gm_joined_utc`; none enumerates the shape, so one more column
     * cannot change their behaviour — but the names and values are pinned
     * here so a later edit cannot quietly rename them either.
     */
    public function testThePreExistingFieldsAreUnchanged(): void
    {
        $wpdb       = $this->installWpdb();
        $wpdb->rows = [
            self::row([
                'group_id'      => '3',
                'joined_at'     => '2026-01-01 00:00:00',
                'gm_joined_utc' => '1767225600',
                'user_status'   => 'member',
            ]),
        ];

        $out = PeepSoGroupRepository::findUserMemberships(7, [3]);

        self::assertArrayHasKey(3, $out, 'keyed by group id, as before');
        self::assertSame('3', $out[3]->group_id);
        self::assertSame('2026-01-01 00:00:00', $out[3]->joined_at);
        self::assertSame('1767225600', $out[3]->gm_joined_utc);
    }

    public function testTheQueryStillFiltersToActiveMembersAndStaysBounded(): void
    {
        $wpdb = $this->installWpdb();

        PeepSoGroupRepository::findUserMemberships(7, [3, 4]);

        self::assertStringContainsString("gm_user_status LIKE 'member%'", $wpdb->lastSql);
        self::assertStringContainsString('gm_group_id IN (3,4)', $wpdb->lastSql);
        self::assertStringContainsString('gm_user_id = 7', $wpdb->lastSql);
        self::assertStringContainsString('LIMIT 200', $wpdb->lastSql);
    }

    public function testNoQueryForAnEmptyGroupListOrAnonymousViewer(): void
    {
        $wpdb = $this->installWpdb();

        self::assertSame([], PeepSoGroupRepository::findUserMemberships(7, []));
        self::assertSame([], PeepSoGroupRepository::findUserMemberships(0, [3]));
        self::assertSame('', $wpdb->lastSql, 'neither case may reach the database');
    }

    /**
     * A FAILED lookup yields no memberships, so every caller reads "not a
     * member" — the closed direction. Pinned because a posting decision
     * built on this must not fail open.
     */
    public function testAFailedLookupYieldsNoMembershipsAndSoFailsClosed(): void
    {
        $wpdb       = $this->installWpdb();
        $wpdb->fail = true;
        $wpdb->rows = [
            self::row(['group_id' => '3', 'joined_at' => 'x', 'gm_joined_utc' => '1', 'user_status' => 'member']),
        ];

        $out = PeepSoGroupRepository::findUserMemberships(7, [3]);

        self::assertSame([], $out, 'an errored query must not look like a membership');
        self::assertNotSame('', $wpdb->last_error);
    }
}
