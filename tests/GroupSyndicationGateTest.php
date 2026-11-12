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

    /** @return array<string, array{mixed, string}> */
    public static function privacyCases(): array
    {
        return [
            'open'       => ['0', '0'],
            'closed'     => ['1', '1'],
            'secret'     => ['2', '2'],
            'missing'    => ['', '0'],
            'unexpected' => ['7', '0'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privacyCases')]
    public function testTheWriterRecordsTheGroupsPrivacyAtPostTime(mixed $groupPrivacy, string $recorded): void
    {
        self::defineMetaDoubles();
        $GLOBALS['__gsg_meta'] = [40 => ['peepso_group_privacy' => $groupPrivacy]];

        PeepSoStatusWriter::attachToGroup(500, 40, 'public_all');

        self::assertSame('40', $GLOBALS['__gsg_meta'][500]['peepso_group_id'] ?? null);
        self::assertSame('public_all', $GLOBALS['__gsg_meta'][500]['_bcc_post_visibility'] ?? null);
        self::assertSame($recorded, $GLOBALS['__gsg_meta'][500][GroupSyndicationGate::META_PRIVACY_AT_POST] ?? null);
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
