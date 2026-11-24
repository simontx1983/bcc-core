<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\NameQuery;

use BCC\Core\Repositories\PeepSoMessageRepository;
use BCC\Core\Repositories\UserMetaRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structure A row 14: the ONE member-name filter (F-512),
 * UserMetaRepository::publicNameMatchSql() with nameQueryLike(), and the
 * inbox search built on it, PeepSoMessageRepository::
 * findConversationsMatchingOrUnknown() and
 * countConversationsMatchingOrUnknown().
 *
 * SOURCE-SHAPE tests, like GroupNameQueryTest: they pin the statement each
 * read builds and the arguments it hands to prepare(). bcc-trust's
 * integration suite runs the same SQL on MySQL 8 and MariaDB, including that
 * a hidden real name, a login, the viewer and a blocked participant are
 * never matched.
 */
#[CoversMethod(UserMetaRepository::class, 'nameQueryLike')]
#[CoversMethod(UserMetaRepository::class, 'publicNameMatchSql')]
#[CoversMethod(PeepSoMessageRepository::class, 'findConversationsMatchingOrUnknown')]
#[CoversMethod(PeepSoMessageRepository::class, 'countConversationsMatchingOrUnknown')]
#[CoversMethod(PeepSoMessageRepository::class, 'findConversationsForUserOrUnknown')]
#[CoversMethod(PeepSoMessageRepository::class, 'countConversationsForUser')]
final class MemberNameQueryTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb = null;

    private NameQueryWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new NameQueryWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
        parent::tearDown();
    }

    // ── The query, normalised ────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function emptyQueries(): iterable
    {
        yield 'empty'        => [''];
        yield 'spaces'       => ['   '];
        yield 'an @ alone'   => ['@'];
        yield '@ and spaces' => [' @  '];
    }

    #[DataProvider('emptyQueries')]
    public function testAnEmptyQueryIsNoFilter(string $q): void
    {
        self::assertNull(UserMetaRepository::nameQueryLike($q));
    }

    public function testOneLeadingAtIsDroppedSoAHandleIsFoundWithIt(): void
    {
        self::assertSame('%alice%', UserMetaRepository::nameQueryLike('  @alice '));
        self::assertSame('%@alice%', UserMetaRepository::nameQueryLike('@@alice'), 'only one');
    }

    public function testLikeWildcardsInTheQueryMatchThemselves(): void
    {
        self::assertSame('%a\\_b\\%c%', UserMetaRepository::nameQueryLike('a_b%c'));
    }

    public function testTheQueryIsCutToTheMaximumInCharactersNotBytes(): void
    {
        self::assertSame(
            '%' . str_repeat('é', UserMetaRepository::NAME_QUERY_MAX) . '%',
            UserMetaRepository::nameQueryLike(str_repeat('é', UserMetaRepository::NAME_QUERY_MAX + 50))
        );
    }

    public function testAQueryThatIsNotUtf8MatchesNothing(): void
    {
        self::assertFalse(UserMetaRepository::nameQueryLike("al\xffice"));
    }

    // ── The member-name filter ───────────────────────────────────────

    public function testOnlyAColumnReferenceIsSplicedIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserMetaRepository::publicNameMatchSql('u.ID) OR 1=1 -- ');
    }

    /**
     * What the condition matches: the handle (first row, or member-{id}),
     * and the display name only where others are shown it.
     */
    public function testTheConditionMatchesOnlyWhatOthersAreShown(): void
    {
        $sql = UserMetaRepository::publicNameMatchSql('q.user_id');

        self::assertStringStartsWith('EXISTS (', $sql);
        self::assertSame(2, substr_count($sql, '%s'), 'two placeholders');
        self::assertSame(2, substr_count($sql, '%'), 'no other percent sign');
        self::assertStringContainsString('bcc_nm_u.ID = q.user_id', $sql, 'the member column');
        self::assertStringNotContainsString('user_email', $sql);
        self::assertSame(0, preg_match('/user_login\s+LIKE/i', $sql), 'the login is never matched');
    }

    /** The whole condition, pinned, so a rewrite that keeps the words cannot change its logic. */
    public function testTheConditionIsExactlyTheReviewedOne(): void
    {
        self::assertSame(
            "EXISTS (SELECT 1 FROM wp_users AS bcc_nm_u"
            . " WHERE bcc_nm_u.ID = q.user_id"
            . " AND (COALESCE(NULLIF(TRIM((SELECT bcc_nm_h.meta_value FROM wp_usermeta AS bcc_nm_h"
            . " WHERE bcc_nm_h.user_id = bcc_nm_u.ID AND bcc_nm_h.meta_key = 'bcc_handle'"
            . " ORDER BY bcc_nm_h.umeta_id ASC LIMIT 1)), ''), CONCAT('member-', bcc_nm_u.ID)) LIKE %s"
            . " OR (bcc_nm_u.display_name LIKE %s"
            . " AND TRIM(bcc_nm_u.display_name) <> ''"
            . " AND LOWER(TRIM(bcc_nm_u.display_name)) <> LOWER(TRIM(bcc_nm_u.user_login))"
            . " AND NOT EXISTS (SELECT 1 FROM wp_usermeta AS bcc_nm_r"
            . " WHERE bcc_nm_r.user_id = bcc_nm_u.ID AND bcc_nm_r.meta_key = 'bcc_privacy_real_name_hidden'"
            . " AND LOWER(TRIM(bcc_nm_r.meta_value)) NOT IN ('', '0', 'false', 'off', 'no')))))",
            UserMetaRepository::publicNameMatchSql('q.user_id')
        );
    }

    // ── The inbox search ─────────────────────────────────────────────

    public function testTheInboxSearchMatchesOtherShownParticipants(): void
    {
        $this->wpdb->queryReturn = 0;

        self::assertSame([], PeepSoMessageRepository::findConversationsMatchingOrUnknown(7, 'ali', 20, 40));

        [$sql, $args] = $this->wpdb->lastPrepared();
        self::assertStringContainsString(
            " AND EXISTS (SELECT 1 FROM wp_peepso_message_participants AS bcc_q_p"
            . " WHERE bcc_q_p.mpart_msg_id = mpart.mpart_msg_id"
            . " AND bcc_q_p.mpart_user_id <> %d"
            . " AND NOT EXISTS (SELECT 1 FROM wp_peepso_blocks AS bcc_q_b"
            . " WHERE (bcc_q_b.blk_user_id = bcc_q_p.mpart_user_id AND bcc_q_b.blk_blocked_id = %d)"
            . " OR (bcc_q_b.blk_user_id = %d AND bcc_q_b.blk_blocked_id = bcc_q_p.mpart_user_id))"
            . ' AND ' . UserMetaRepository::publicNameMatchSql('bcc_q_p.mpart_user_id') . ')',
            $sql,
            'other participants, not blocked either way, by the shared name filter'
        );
        self::assertSame([7, 7, 7, 7, 7, '%ali%', '%ali%', 20, 40], $args, 'placeholders and arguments in one order');
    }

    public function testTheUnfilteredInboxReadIsExactlyAsBefore(): void
    {
        $this->wpdb->queryReturn = 0;

        PeepSoMessageRepository::findConversationsForUserOrUnknown(7, 20, 40);
        [$plain, $plainArgs] = $this->wpdb->lastPrepared();
        PeepSoMessageRepository::findConversationsMatchingOrUnknown(7, '  ', 20, 40);
        [$empty, $emptyArgs] = $this->wpdb->lastPrepared();

        self::assertStringNotContainsString('bcc_q_p', $plain);
        self::assertSame([7, 7, 20, 40], $plainArgs);
        self::assertSame($plain, $empty, 'an empty query is the unfiltered read');
        self::assertSame($plainArgs, $emptyArgs);
    }

    public function testANonUtf8QueryFindsNoConversation(): void
    {
        $this->wpdb->queryReturn = 0;

        PeepSoMessageRepository::findConversationsMatchingOrUnknown(7, "\xff", 20, 0);
        [$sql, $args] = $this->wpdb->lastPrepared();

        self::assertStringContainsString('AND last_msg.ID IS NOT NULL AND 1 = 0', $sql);
        self::assertSame([7, 7, 20, 0], $args);
    }

    public function testAFailedSearchReadIsUnknownNotEmpty(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        self::assertNull(PeepSoMessageRepository::findConversationsMatchingOrUnknown(7, 'ali', 20, 0));
        self::assertNull(PeepSoMessageRepository::countConversationsMatchingOrUnknown(7, 'ali'));
    }

    public function testTheSearchCountCountsTheSameConversations(): void
    {
        $this->wpdb->resultsToReturn = [['n' => '3']];
        $this->wpdb->queryReturn     = 1;

        self::assertSame(3, PeepSoMessageRepository::countConversationsMatchingOrUnknown(7, 'ali'));

        [$sql, $args] = $this->wpdb->lastPrepared();
        self::assertStringContainsString('COUNT(DISTINCT mpart.mpart_msg_id) AS n', $sql);
        self::assertStringContainsString('bcc_q_p.mpart_user_id <> %d', $sql);
        self::assertSame([7, 7, 7, 7, 7, '%ali%', '%ali%'], $args);
    }

    public function testTheUnfilteredCountIsExactlyAsBefore(): void
    {
        $this->wpdb->varToReturn = '5';

        self::assertSame(5, PeepSoMessageRepository::countConversationsForUser(7));

        [$sql, $args] = $this->wpdb->lastPrepared();
        self::assertStringNotContainsString('bcc_q_p', $sql);
        self::assertSame([7, 7], $args);
    }
}

/** A capturing `$wpdb` double: records every prepare(), never runs SQL. */
final class NameQueryWpdb
{
    public string $prefix   = 'wp_';
    public string $posts    = 'wp_posts';
    public string $users    = 'wp_users';
    public string $usermeta = 'wp_usermeta';

    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $prepared = [];

    public string $last_error = '';

    /** A driver error raised by the next query(), or '' for success. */
    public string $errorOnQuery = '';

    /** @var int|false */
    public $queryReturn = 1;

    /** @var list<array<string, string>> */
    public array $resultsToReturn = [];

    /** @var list<object> */
    public array $last_result = [];

    /** @var int|string|null */
    public $varToReturn = 0;

    /** @param mixed ...$args */
    public function prepare(string $sql, ...$args): string
    {
        $this->prepared[] = [$sql, $args];

        return $sql;
    }

    /** WordPress's wpdb::esc_like(). */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** @return int|false */
    public function query(string $sql)
    {
        $this->last_error = $this->errorOnQuery;
        if ($this->errorOnQuery !== '') {
            return false;
        }
        $this->last_result = array_map(static fn (array $r): object => (object) $r, $this->resultsToReturn);

        return $this->queryReturn;
    }

    /** @return int|string|null */
    public function get_var(string $sql)
    {
        return $this->varToReturn;
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function lastPrepared(): array
    {
        $last = end($this->prepared);
        TestCase::assertIsArray($last, 'a statement was prepared');

        return $last;
    }
}
