<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\Syndication;

use BCC\Core\Feed\GroupSyndicationGate;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The shared group-syndication rule (F-23 option B + Gate 12), and the
 * writer that records what it needs.
 *
 *   group open now                                → syndicates
 *   group non-open now, made while non-open       → syndicates (Gate 12)
 *   group non-open now, made while open/no record → dropped
 *   non-open set unknown (null)                   → only "made while
 *                                                    non-open" syndicates
 *
 * Separate processes: the writer test declares global WordPress meta
 * doubles that must not leak into sibling suites.
 */
#[CoversClass(GroupSyndicationGate::class)]
#[CoversMethod(PeepSoStatusWriter::class, 'attachToGroup')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupSyndicationGateTest extends TestCase
{
    public function testNonGroupPostAlwaysPasses(): void
    {
        self::assertTrue(GroupSyndicationGate::allows([], '', [7]));
        self::assertTrue(GroupSyndicationGate::allows([], '', null));
    }

    public function testOpenGroupPasses(): void
    {
        self::assertTrue(GroupSyndicationGate::allows([7], '0', [9]));
        self::assertTrue(GroupSyndicationGate::allows([7], '', [9]));
    }

    public function testMadeWhileNonOpenPassesWhateverTheGroupIsNow(): void
    {
        self::assertTrue(GroupSyndicationGate::allows([7], '1', [7]));
        self::assertTrue(GroupSyndicationGate::allows([7], '2', [7]));
        self::assertTrue(GroupSyndicationGate::allows([7], '1', null));
    }

    public function testMadeWhileOpenOrUnrecordedDropsOnceNonOpen(): void
    {
        self::assertFalse(GroupSyndicationGate::allows([7], '0', [7]));
        self::assertFalse(GroupSyndicationGate::allows([7], '', [7]));
        self::assertFalse(GroupSyndicationGate::allows([7], null, [7]));
        self::assertFalse(GroupSyndicationGate::allows([7], 'junk', [7]));
    }

    public function testAnyNonOpenGroupOfSeveralDrops(): void
    {
        self::assertFalse(GroupSyndicationGate::allows([7, 12], '0', [12]));
    }

    public function testUnknownSetFailsClosed(): void
    {
        self::assertFalse(GroupSyndicationGate::allows([7], '0', null));
    }

    public function testSqlPredicateAddsNothingWhenNoGroupIsNonOpen(): void
    {
        self::assertSame(['where' => '', 'params' => []], GroupSyndicationGate::sqlPredicate('wp_postmeta', []));
    }

    public function testSqlPredicateBindsTheNonOpenIdsAndKeepsTheGate12Escape(): void
    {
        $p = GroupSyndicationGate::sqlPredicate('wp_postmeta', [7, 9, 7, 0]);
        self::assertSame(['7', '9'], $p['params'], 'deduped, positive ids only, as strings');
        self::assertStringContainsString("gsg_g.meta_value IN (%s,%s)", $p['where']);
        self::assertStringContainsString("_bcc_post_group_privacy_at_post' AND gsg_at.meta_value IN ('1','2')", $p['where']);
        self::assertStringStartsWith('(NOT EXISTS', $p['where']);
    }

    public function testSqlPredicateFailsClosedOnUnknown(): void
    {
        $p = GroupSyndicationGate::sqlPredicate('wp_postmeta', null);
        self::assertSame([], $p['params']);
        self::assertStringNotContainsString('gsg_g.meta_value IN', $p['where']);
        self::assertStringContainsString("gsg_g.meta_key = 'peepso_group_id')", $p['where']);
        self::assertStringContainsString('gsg_at.meta_value IN', $p['where'], 'Gate 12 escape kept');
    }

    /**
     * What the group privacy read (PeepSoGroupPrivacy::privacyForGroups,
     * F-460) hands back, and the stamp the writer must record.
     *
     * The value side is the read's STRICTEST row, as the engine computes it.
     * Stray values such as '7' are decided by that SQL, and are pinned
     * where the SQL is executed (PeepSoGroupPrivacyReadTest).
     *
     * @return array<string, array{string, list<object>, string}>
     */
    public static function privacyCases(): array
    {
        return [
            'open'           => ['ok', [(object) ['post_id' => '40', 'strictest' => '0']], '0'],
            'closed'         => ['ok', [(object) ['post_id' => '40', 'strictest' => '1']], '1'],
            'secret'         => ['ok', [(object) ['post_id' => '40', 'strictest' => '2']], '2'],
            'no privacy row' => ['ok', [], '0'],
            // An unreadable privacy is recorded as open: the direction that
            // DROPS a public_all post once the group is non-open, never leaks.
            'read failed'    => ['error', [], '0'],
            'pre-flush bail' => ['preflush', [(object) ['post_id' => '40', 'strictest' => '2']], '0'],
        ];
    }

    /**
     * @param list<object> $rows
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('privacyCases')]
    public function testTheWriterRecordsTheGroupsPrivacyAtPostTime(string $mode, array $rows, string $recorded): void
    {
        self::defineMetaDoubles();
        $GLOBALS['__gsg_meta'] = [];
        $GLOBALS['wpdb']       = self::privacyWpdb($mode, $rows);

        PeepSoStatusWriter::attachToGroup(500, 40, 'public_all');

        self::assertSame('40', $GLOBALS['__gsg_meta'][500]['peepso_group_id'] ?? null);
        self::assertSame('public_all', $GLOBALS['__gsg_meta'][500]['_bcc_post_visibility'] ?? null);
        self::assertSame($recorded, $GLOBALS['__gsg_meta'][500][GroupSyndicationGate::META_PRIVACY_AT_POST] ?? null);
    }

    /**
     * ⚠ The writer must not read privacy through get_post_meta() any more. A
     * failed meta load is cached as "no rows", and `$single = true` saw only
     * the first row. The meta double would hand back a secret '2' here, so a
     * writer that still read it would stamp '2' while the table read says 1.
     */
    public function testTheWriterReadsPrivacyFromTheTableNotTheMetaCache(): void
    {
        self::defineMetaDoubles();
        $GLOBALS['__gsg_meta'] = [40 => ['peepso_group_privacy' => '2']];
        $GLOBALS['wpdb']       = self::privacyWpdb('ok', [(object) ['post_id' => '40', 'strictest' => '1']]);

        PeepSoStatusWriter::attachToGroup(500, 40, 'public_all');

        self::assertSame('1', $GLOBALS['__gsg_meta'][500][GroupSyndicationGate::META_PRIVACY_AT_POST] ?? null);
    }

    /**
     * A `$wpdb` double for the privacy read. 'preflush' returns false BEFORE
     * flush(), with no last_error and the given rows left in last_result.
     *
     * @param list<object> $rows
     */
    private static function privacyWpdb(string $mode, array $rows): object
    {
        return new class ($mode, $rows) {
            public string $postmeta = 'wp_postmeta';
            public string $last_error = '';
            /** @var list<object> */
            public array $last_result;

            /** @param list<object> $rows */
            public function __construct(private string $mode, private array $rows)
            {
                $this->last_result = $mode === 'preflush' ? $rows : [];
            }

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            /** @return int|false */
            public function query(string $sql)
            {
                if ($this->mode === 'preflush') {
                    return false;
                }
                $this->last_error  = '';
                $this->last_result = [];
                if ($this->mode === 'error') {
                    $this->last_error = 'Lost connection to MySQL server during query';

                    return false;
                }
                $this->last_result = $this->rows;

                return count($this->rows);
            }
        };
    }

    private static function defineMetaDoubles(): void
    {
        if (\function_exists('update_post_meta')) {
            return;
        }
        eval(<<<'PHP'
            function update_post_meta(int $id, string $key, $value): bool
            {
                $GLOBALS['__gsg_meta'][$id][$key] = $value;
                return true;
            }
            function get_post_meta(int $id, string $key = '', bool $single = false)
            {
                return $GLOBALS['__gsg_meta'][$id][$key] ?? '';
            }
            function do_action(string $hook, ...$args): void {}
            PHP);
    }
}
