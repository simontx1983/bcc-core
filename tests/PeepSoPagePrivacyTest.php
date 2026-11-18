<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoPagePrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoPagePrivacy — the shared SECRET-page rule.
 *
 * Lists use notSecretSql(); one page on its own uses isVisibleTo(), which
 * lets the page's members and invitees through. A failed read counts as
 * secret.
 *
 * The fake $wpdb answers the privacy probe and the membership read from
 * `$secret` / `$statuses`, and can fail either one. The SQL itself is run
 * against MySQL 8 and MariaDB by bcc-trust's integration suite.
 */
#[CoversClass(PeepSoPagePrivacy::class)]
final class PeepSoPagePrivacyTest extends TestCase
{
    /**
     * @var object{
     *   prefix: string, postmeta: string, last_error: string, last_result: mixed,
     *   secret: bool, statuses: list<string>, failPrivacy: bool, failMembers: bool,
     *   prepared: list<array{0: string, 1: list<mixed>}>, queries: list<string>
     * }
     */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $prefix   = 'wp_';
            public string $postmeta = 'wp_postmeta';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            public bool $secret = false;
            /** @var list<string> */
            public array $statuses = [];
            public bool $failPrivacy = false;
            public bool $failMembers = false;

            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];
            /** @var list<string> */
            public array $queries = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries[] = $sql;
                $members = str_contains($sql, 'peepso_page_members');

                if ($members ? $this->failMembers : $this->failPrivacy) {
                    // wpdb leaves the PREVIOUS last_result on some failure paths.
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }

                $this->last_result = $members
                    ? array_map(static fn (string $s): object => (object) ['pm_user_status' => $s], $this->statuses)
                    : ($this->secret ? [(object) ['1' => '1']] : []);
                return count($this->last_result);
            }

            public function get_var(?string $sql = null): ?string
            {
                $row = is_array($this->last_result) ? ($this->last_result[0] ?? null) : null;
                if (!is_object($row)) {
                    return null;
                }
                $values = array_values(get_object_vars($row));
                return isset($values[0]) ? (string) $values[0] : null;
            }

            /** @return list<string> */
            public function get_col(?string $sql = null): array
            {
                $out = [];
                foreach (is_array($this->last_result) ? $this->last_result : [] as $row) {
                    $values = array_values(get_object_vars($row));
                    $out[]  = (string) ($values[0] ?? '');
                }
                return $out;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── notSecretSql(): the list fragment ──────────────────────────────

    public function testTheListFragmentExcludesAPageWithAnySecretRow(): void
    {
        $sql = PeepSoPagePrivacy::notSecretSql('p.ID');

        self::assertSame(
            "NOT EXISTS (SELECT 1 FROM wp_postmeta bcc_page_priv"
            . " WHERE bcc_page_priv.post_id = p.ID"
            . " AND bcc_page_priv.meta_key = 'peepso_page_privacy'"
            . " AND CAST(bcc_page_priv.meta_value AS UNSIGNED) = 2)",
            $sql
        );
        self::assertStringNotContainsString('%', $sql, 'safe inside a string later given to prepare()');
        self::assertSame([], $this->wpdb->queries, 'building the fragment runs nothing');
    }

    public function testTheListFragmentTakesABareColumnToo(): void
    {
        self::assertStringContainsString('bcc_page_priv.post_id = page_id', PeepSoPagePrivacy::notSecretSql('page_id'));
    }

    /** @return iterable<string, array{string}> */
    public static function notAColumn(): iterable
    {
        yield 'empty'              => [''];
        yield 'injected statement' => ['p.ID) OR (1=1'];
        yield 'quoted'             => ["p.ID'"];
        yield 'three parts'        => ['db.p.ID'];
        yield 'leading digit'      => ['1p.ID'];
        yield 'trailing newline'   => ["p.ID\n"];
    }

    #[DataProvider('notAColumn')]
    public function testTheListFragmentRefusesAnythingButAColumn(string $idColumn): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PeepSoPagePrivacy::notSecretSql($idColumn);
    }

    // ── isSecretOrUnknown(): the same rule for one page ────────────────

    public function testASecretPageIsSecret(): void
    {
        $this->wpdb->secret = true;

        self::assertTrue(PeepSoPagePrivacy::isSecretOrUnknown(42));
    }

    public function testAPageWithNoSecretRowIsNotSecret(): void
    {
        self::assertFalse(PeepSoPagePrivacy::isSecretOrUnknown(42));
    }

    public function testTheOnePageProbeUsesTheListFragmentsPredicate(): void
    {
        PeepSoPagePrivacy::isSecretOrUnknown(42);

        $list = PeepSoPagePrivacy::notSecretSql('p.ID');
        self::assertCount(1, $this->wpdb->prepared);
        [$sql, $args] = $this->wpdb->prepared[0];
        self::assertSame([42], $args);
        self::assertSame(
            str_replace('p.ID', '%d', substr($list, strlen('NOT EXISTS ('), -1)) . ' LIMIT 1',
            $sql,
            'one predicate: the probe and the list fragment cannot drift apart'
        );
    }

    public function testAFailedPrivacyReadCountsAsSecretEvenWithAnEmptyStaleResult(): void
    {
        $this->wpdb->last_result = [];
        $this->wpdb->failPrivacy = true;

        self::assertTrue(PeepSoPagePrivacy::isSecretOrUnknown(42));
    }

    public function testAnErrorLeftByAnEarlierQueryIsNotThisReadsFailure(): void
    {
        $this->wpdb->last_error = 'Deadlock found from an unrelated query';

        self::assertFalse(PeepSoPagePrivacy::isSecretOrUnknown(42));
    }

    public function testNoPageIdCountsAsSecretWithoutAQuery(): void
    {
        self::assertTrue(PeepSoPagePrivacy::isSecretOrUnknown(0));
        self::assertTrue(PeepSoPagePrivacy::isSecretOrUnknown(-5));
        self::assertSame([], $this->wpdb->queries);
    }

    // ── isVisibleTo(): one page, members let through ───────────────────

    public function testAnOpenPageIsVisibleToEveryoneWithoutAMembershipRead(): void
    {
        self::assertTrue(PeepSoPagePrivacy::isVisibleTo(42, 0));
        self::assertTrue(PeepSoPagePrivacy::isVisibleTo(42, 7));
        self::assertSame([], $this->membershipQueries());
    }

    public function testASecretPageIsHiddenFromAnonymousViewersWithoutAMembershipRead(): void
    {
        $this->wpdb->secret = true;

        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(42, 0));
        self::assertSame([], $this->membershipQueries());
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function memberships(): iterable
    {
        yield 'member'                  => [['member'], true];
        yield 'moderator'               => [['member_moderator'], true];
        yield 'manager'                 => [['member_manager'], true];
        yield 'owner'                   => [['member_owner'], true];
        yield 'no row'                  => [[], false];
        yield 'invited, not yet joined' => [['pending_user'], true];
        yield 'asked to join'           => [['pending_admin'], false];
        yield 'banned'                  => [['banned'], false];
        yield 'blocked invites'         => [['block_invites'], false];
        yield 'banned beside a member row' => [['banned', 'member'], false];
        yield 'member beside a banned row' => [['member', 'banned'], false];
        yield 'member beside a pending row' => [['pending_user', 'member'], true];
        yield 'invite beside a banned row'  => [['banned', 'pending_user'], false];
        yield 'banned beside an invite'     => [['pending_user', 'banned'], false];
    }

    /** @param list<string> $statuses */
    #[DataProvider('memberships')]
    public function testASecretPageIsVisibleOnlyToItsMembers(array $statuses, bool $visible): void
    {
        $this->wpdb->secret   = true;
        $this->wpdb->statuses = $statuses;

        self::assertSame($visible, PeepSoPagePrivacy::isVisibleTo(42, 7));
    }

    public function testTheMembershipReadIsOnePairBoundedAndPutsBansFirst(): void
    {
        $this->wpdb->secret   = true;
        $this->wpdb->statuses = ['member'];

        PeepSoPagePrivacy::isVisibleTo(42, 7);

        $reads = array_values(array_filter(
            $this->wpdb->prepared,
            static fn (array $p): bool => str_contains($p[0], 'peepso_page_members')
        ));
        self::assertCount(1, $reads);
        [$sql, $args] = $reads[0];
        self::assertStringContainsString('FROM wp_peepso_page_members', $sql);
        self::assertStringContainsString("ORDER BY (pm_user_status = 'banned') DESC, pm_id ASC", $sql);
        self::assertSame([42, 7, 10], $args, 'page, user, row cap');
    }

    public function testAFailedMembershipReadHidesASecretPage(): void
    {
        $this->wpdb->secret      = true;
        $this->wpdb->statuses    = ['member_owner'];
        $this->wpdb->failMembers = true;

        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(42, 7));
    }

    public function testAFailedPrivacyReadStillLetsAMemberIn(): void
    {
        $this->wpdb->failPrivacy = true;
        $this->wpdb->statuses    = ['member'];

        self::assertTrue(PeepSoPagePrivacy::isVisibleTo(42, 7), 'unknown counts as secret, and members see secret pages');
    }

    public function testAFailedPrivacyReadHidesThePageFromANonMember(): void
    {
        $this->wpdb->failPrivacy = true;

        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(42, 7));
        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(42, 0));
    }

    public function testNoPageIdIsNeverVisible(): void
    {
        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(0, 7));
        self::assertSame([], $this->wpdb->queries);
    }

    /** @return list<string> */
    private function membershipQueries(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'peepso_page_members')
        ));
    }
}
