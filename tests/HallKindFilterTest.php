<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The optional Hall-kind filter on listHalls() and countHalls() (topic
 * Halls): a chain Hall has a chain tag naming a chain; a topic Hall has a
 * topic and no such chain tag.
 *
 * SOURCE-SHAPE tests, like GroupNameQueryTest: they pin the statement each
 * read builds and the arguments it hands to prepare(). bcc-trust's
 * integration suite runs the reads against MySQL 8 and MariaDB.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'listHalls')]
#[CoversMethod(PeepSoGroupRepository::class, 'countHalls')]
final class HallKindFilterTest extends TestCase
{
    private const CHAIN_TAG_ARGS = ['_bcc_chain_tag', '^[1-9][0-9]*$'];

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

    /**
     * The statement's filter tail (everything after the status match, up to
     * GROUP BY when the read has one) and the arguments after the status.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filterTail(string $sql, array $args): array
    {
        $start = strpos($sql, 'p.post_status = %s');
        self::assertNotFalse($start);
        $tail = substr($sql, $start + strlen('p.post_status = %s'));
        $end  = strpos($tail, 'GROUP BY');
        $tail = trim($end === false ? $tail : substr($tail, 0, $end));

        $status = array_search('publish', $args, true);
        self::assertIsInt($status);
        $after = array_slice($args, $status + 1);
        // listHalls() ends with LIMIT and OFFSET; countHalls() has neither.
        if (str_contains($sql, 'LIMIT %d OFFSET %d')) {
            $after = array_slice($after, 0, -2);
        }

        return [(string) preg_replace('/\s+/', ' ', $tail), array_values($after)];
    }

    /** @return array<string, array{\Closure(?string): mixed}> */
    public static function reads(): array
    {
        return [
            'halls'       => [static fn (?string $kind) => PeepSoGroupRepository::listHalls(null, 0, 20, '', $kind)],
            'halls count' => [static fn (?string $kind) => PeepSoGroupRepository::countHalls(null, '', $kind)],
        ];
    }

    /** No kind leaves each read exactly as it was before the filter existed. */
    #[DataProvider('reads')]
    public function testNoKindLeavesTheReadAsItWas(\Closure $read): void
    {
        $read(null);
        [$sql, $args] = $this->last();

        self::assertStringNotContainsString('hk_chain', $sql);
        self::assertStringNotContainsString('hk_topic', $sql);
        self::assertNotContains('_bcc_hall_topic', $args);
        self::assertAligned($sql, $args);
    }

    #[DataProvider('reads')]
    public function testChainKeepsHallsWhoseChainTagNamesAChain(\Closure $read): void
    {
        $read(PeepSoGroupRepository::HALL_KIND_CHAIN);
        [$sql, $args] = $this->last();
        [$tail, $tailArgs] = self::filterTail($sql, $args);

        self::assertSame(
            'AND EXISTS (SELECT 1 FROM wp_postmeta hk_chain WHERE hk_chain.post_id = p.ID AND hk_chain.meta_key = %s AND hk_chain.meta_value REGEXP %s)',
            $tail
        );
        self::assertSame(self::CHAIN_TAG_ARGS, $tailArgs);
        self::assertAligned($sql, $args);
    }

    /** ⭐ A topic Hall has a non-empty topic AND no chain tag: the two kinds never overlap. */
    #[DataProvider('reads')]
    public function testTopicKeepsHallsWithATopicAndNoChain(\Closure $read): void
    {
        $read(PeepSoGroupRepository::HALL_KIND_TOPIC);
        [$sql, $args] = $this->last();
        [$tail, $tailArgs] = self::filterTail($sql, $args);

        self::assertSame(
            "AND EXISTS (SELECT 1 FROM wp_postmeta hk_topic WHERE hk_topic.post_id = p.ID AND hk_topic.meta_key = %s AND hk_topic.meta_value <> '')"
            . ' AND NOT EXISTS (SELECT 1 FROM wp_postmeta hk_chain WHERE hk_chain.post_id = p.ID AND hk_chain.meta_key = %s AND hk_chain.meta_value REGEXP %s)',
            $tail
        );
        self::assertSame(['_bcc_hall_topic', ...self::CHAIN_TAG_ARGS], $tailArgs);
        self::assertAligned($sql, $args);
    }

    /** @return array<string, array{string}> */
    public static function unknownKinds(): array
    {
        return ['empty' => [''], 'other word' => ['general'], 'case' => ['Chain'], 'padded' => [' topic']];
    }

    /** Any other value matches nothing, rather than every Hall. */
    #[DataProvider('unknownKinds')]
    public function testAnUnknownKindMatchesNothing(string $kind): void
    {
        foreach (self::reads() as [$read]) {
            $read($kind);
            [$sql, $args] = $this->last();
            [$tail, $tailArgs] = self::filterTail($sql, $args);

            self::assertSame('AND 1 = 0', $tail);
            self::assertSame([], $tailArgs);
            self::assertAligned($sql, $args);
        }
    }

    /** The page and its total filter identically, so they agree. */
    #[DataProvider('kinds')]
    public function testTheListAndTheCountFilterTheSame(?string $kind): void
    {
        PeepSoGroupRepository::listHalls(7, 40, 20, 'alpha', $kind);
        $list = self::filterTail(...$this->last());
        PeepSoGroupRepository::countHalls(7, 'alpha', $kind);
        $count = self::filterTail(...$this->last());

        self::assertSame($list, $count);
    }

    /** @return array<string, array{?string}> */
    public static function kinds(): array
    {
        return ['none' => [null], 'chain' => [PeepSoGroupRepository::HALL_KIND_CHAIN], 'topic' => [PeepSoGroupRepository::HALL_KIND_TOPIC]];
    }

    /** With a chain id and a name too, every argument still lands on its own placeholder. */
    public function testItCombinesWithTheChainAndNameFilters(): void
    {
        PeepSoGroupRepository::listHalls(7, 40, 20, 'alpha', PeepSoGroupRepository::HALL_KIND_TOPIC);
        [$sql, $args] = $this->last();

        self::assertSame(
            ['_bcc_group_kind', 'hall', '_bcc_chain_tag', 7, 'member%', 'peepso-group', 'publish', '%alpha%', '_bcc_hall_topic', ...self::CHAIN_TAG_ARGS, 20, 40],
            $args
        );
        self::assertMatchesRegularExpression('/p\.post_title LIKE %s\s+AND EXISTS \(SELECT 1 FROM wp_postmeta hk_topic/', $sql);
        self::assertAligned($sql, $args);

        PeepSoGroupRepository::countHalls(7, 'alpha', PeepSoGroupRepository::HALL_KIND_CHAIN);
        [$sql, $args] = $this->last();

        self::assertSame(
            ['_bcc_group_kind', 'hall', '_bcc_chain_tag', 7, 'peepso-group', 'publish', '%alpha%', ...self::CHAIN_TAG_ARGS],
            $args
        );
        self::assertAligned($sql, $args);
    }
}
