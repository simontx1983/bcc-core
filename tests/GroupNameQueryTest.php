<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The optional name filter on the group list reads (structure A, route map
 * rows 13 and 15): listBrowsableGroupIds(), listHalls() and countHalls().
 *
 * SOURCE-SHAPE tests, like PeepSoGroupPrivacyTest: they pin the statement
 * each read builds and the arguments it hands to prepare(). bcc-trust's
 * integration suite runs the reads against MySQL 8 and MariaDB, including
 * that a name never finds a secret group.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'listBrowsableGroupIds')]
#[CoversMethod(PeepSoGroupRepository::class, 'listHalls')]
#[CoversMethod(PeepSoGroupRepository::class, 'countHalls')]
final class GroupNameQueryTest extends TestCase
{
    /** @var object{prepared: list<array{0: string, 1: list<mixed>}>} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];

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

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                return [];
            }

            /** @return list<object> */
            public function get_results(string $sql): array
            {
                return [];
            }

            public function get_var(string $sql): string
            {
                return '0';
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** @return array{0: string, 1: list<mixed>} the last statement prepared */
    private function last(): array
    {
        $last = end($this->wpdb->prepared);
        self::assertIsArray($last, 'the read prepared a statement');
        return $last;
    }

    /** Every placeholder in the statement has exactly one argument, in order. */
    private static function assertAligned(string $sql, array $args): void
    {
        self::assertSame(preg_match_all('/%[sd]/', $sql), count($args), 'placeholders and arguments line up');
    }

    /** @return array<string, array{\Closure(string): mixed}> */
    public static function reads(): array
    {
        return [
            'browsable ids' => [static fn (string $q) => PeepSoGroupRepository::listBrowsableGroupIds(10, $q)],
            'halls'         => [static fn (string $q) => PeepSoGroupRepository::listHalls(3, 0, 20, $q)],
            'halls count'   => [static fn (string $q) => PeepSoGroupRepository::countHalls(3, $q)],
        ];
    }

    /** No query (or only spaces) leaves each read exactly as it was. */
    #[DataProvider('reads')]
    public function testNoQueryLeavesTheReadAsItWas(\Closure $read): void
    {
        $read('');
        [$plainSql, $plainArgs] = $this->last();
        $read('   ');
        [$spacesSql, $spacesArgs] = $this->last();

        self::assertStringNotContainsString('post_title LIKE', $plainSql);
        self::assertSame([$plainSql, $plainArgs], [$spacesSql, $spacesArgs]);
        self::assertAligned($plainSql, $plainArgs);
    }

    /** A query becomes one prepared title match: no query text in the SQL. */
    #[DataProvider('reads')]
    public function testAQueryIsOnePreparedTitleMatch(\Closure $read): void
    {
        $read("  Alpha ' OR 1=1 --  ");
        [$sql, $args] = $this->last();

        self::assertStringContainsString('p.post_title LIKE %s', $sql);
        self::assertStringNotContainsString('Alpha', $sql, 'the query never reaches the SQL text');
        self::assertContains("%Alpha ' OR 1=1 --%", $args, 'trimmed, wrapped, passed as an argument');
        self::assertAligned($sql, $args);
    }

    /** The name match sits in the same statement as the secret exclusion. */
    public function testTheBrowsableNameMatchSitsBesideTheSecretExclusion(): void
    {
        PeepSoGroupRepository::listBrowsableGroupIds(10, 'alpha');
        [$sql, $args] = $this->last();

        self::assertMatchesRegularExpression('/NOT EXISTS \(.*\)\s+AND p\.post_title LIKE %s/s', $sql);
        self::assertSame(['peepso-group', 'publish', '%alpha%', 10], $args);
    }

    /** LIKE's wildcards in a query match themselves, never widen the match. */
    public function testWildcardsInTheQueryMatchThemselves(): void
    {
        PeepSoGroupRepository::listBrowsableGroupIds(10, '50%_off\\');
        [, $args] = $this->last();

        self::assertSame('%50\\%\\_off\\\\%', $args[2]);
    }

    /** At most NAME_QUERY_MAX characters (not bytes) are used. */
    public function testTheQueryIsBoundedInCharacters(): void
    {
        PeepSoGroupRepository::listBrowsableGroupIds(10, str_repeat('é', PeepSoGroupRepository::NAME_QUERY_MAX + 50));
        [, $args] = $this->last();

        self::assertSame('%' . str_repeat('é', PeepSoGroupRepository::NAME_QUERY_MAX) . '%', $args[2]);
    }

    /** A query that is not valid UTF-8 matches nothing, rather than everything. */
    #[DataProvider('reads')]
    public function testAnInvalidQueryMatchesNothing(\Closure $read): void
    {
        $read("\xff\xfe");
        [$sql, $args] = $this->last();

        self::assertStringContainsString(' AND 1 = 0', $sql);
        self::assertStringNotContainsString('post_title LIKE', $sql);
        self::assertAligned($sql, $args);
    }

    /** The Halls page and its total take the same filters, so they agree. */
    public function testTheHallsPageAndItsTotalTakeTheSameNameFilter(): void
    {
        PeepSoGroupRepository::listHalls(3, 40, 20, 'akash');
        [$listSql, $listArgs] = $this->last();
        PeepSoGroupRepository::countHalls(3, 'akash');
        [$countSql, $countArgs] = $this->last();

        foreach ([$listSql, $countSql] as $sql) {
            self::assertMatchesRegularExpression('/p\.post_status = %s\s+AND p\.post_title LIKE %s/', $sql);
        }
        self::assertSame(1, count(array_keys($listArgs, '%akash%', true)));
        self::assertSame(1, count(array_keys($countArgs, '%akash%', true)));
        self::assertSame([20, 40], array_slice($listArgs, -2), 'limit and offset still come last');
    }
}
