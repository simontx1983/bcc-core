<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\SystemHealthPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape coupling between DegradationMetrics::healthSnapshot()
 * and the System Health page's degradation table.
 *
 * Regression anchor: an earlier renderer read an event-major shape
 * (`subsystems[x]['events'][y]['current'|'previous']` + `any_nonzero`)
 * that the producer never emitted, so the page's most operationally
 * important panel rendered "all quiet" during real incidents. These
 * fixtures use the REAL window-major snapshot shape (same as
 * DegradationAlerterTest) — if either side drifts again, this fails.
 */
#[CoversClass(SystemHealthPage::class)]
final class SystemHealthDegradationRowsTest extends TestCase
{
    public function testRealSnapshotShapeProducesRows(): void
    {
        $snapshot = [
            'any_active' => true,
            'subsystems' => [
                'auth_mail' => [
                    'current_hour'  => ['send_failed' => 3],
                    'previous_hour' => ['send_failed' => 1],
                ],
                'peepso_absence' => [
                    'current_hour'  => [],
                    'previous_hour' => ['reaction_writer_set' => 2],
                ],
            ],
        ];

        self::assertSame([
            ['subsystem' => 'auth_mail',      'event' => 'send_failed',         'current' => 3, 'previous' => 1],
            ['subsystem' => 'peepso_absence', 'event' => 'reaction_writer_set', 'current' => 0, 'previous' => 2],
        ], SystemHealthPage::degradationRows($snapshot));
    }

    public function testEventPresentInOnlyOneWindowStillRendersBothColumns(): void
    {
        $rows = SystemHealthPage::degradationRows([
            'any_active' => true,
            'subsystems' => [
                'nft_indexer' => [
                    'current_hour'  => ['provider_error' => 5, 'stall' => 1],
                    'previous_hour' => ['stall' => 4],
                ],
            ],
        ]);

        self::assertSame([
            ['subsystem' => 'nft_indexer', 'event' => 'provider_error', 'current' => 5, 'previous' => 0],
            ['subsystem' => 'nft_indexer', 'event' => 'stall',          'current' => 1, 'previous' => 4],
        ], $rows);
    }

    public function testQuietSnapshotYieldsNoRows(): void
    {
        // healthSnapshot omits fully-quiet subsystems entirely.
        self::assertSame([], SystemHealthPage::degradationRows([
            'any_active' => false,
            'subsystems' => [],
        ]));
    }

    public function testMalformedInputIsIgnoredNotFatal(): void
    {
        self::assertSame([], SystemHealthPage::degradationRows([]));
        self::assertSame([], SystemHealthPage::degradationRows(['subsystems' => 'nope']));
        self::assertSame([], SystemHealthPage::degradationRows([
            'subsystems' => ['bad' => 'not-an-array'],
        ]));
    }
}
