<?php

declare(strict_types=1);

namespace BCC\Core\Observability\Tests;

use BCC\Core\Observability\DegradationAlerter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The alerter's decision logic: which subsystems are "alerting" (sustained
 * degradation past a threshold) and the transitions that drive ONE alert on
 * entry + ONE on recovery (no per-tick spam).
 */
#[CoversClass(DegradationAlerter::class)]
final class DegradationAlerterTest extends TestCase
{
    /** @param array<string, array{current_hour?: array<string,int>, previous_hour?: array<string,int>}> $subsystems */
    private static function snapshot(array $subsystems): array
    {
        return ['any_active' => $subsystems !== [], 'subsystems' => $subsystems];
    }

    // ── computeAlerting ─────────────────────────────────────────────────

    public function testEmptySnapshotAlertsNothing(): void
    {
        self::assertSame([], DegradationAlerter::computeAlerting(self::snapshot([]), 5));
        self::assertSame([], DegradationAlerter::computeAlerting([], 5));
    }

    public function testBelowThresholdDoesNotAlert(): void
    {
        $snap = self::snapshot([
            'peepso_absence' => ['current_hour' => ['activation' => 2], 'previous_hour' => []],
        ]);
        self::assertSame([], DegradationAlerter::computeAlerting($snap, 5), 'sum 2 < 5');
    }

    public function testSummedAcrossWindowsAndEventsTripsThreshold(): void
    {
        $snap = self::snapshot([
            // 3 + 3 = 6 ≥ 5 → alerting
            'throttle' => ['current_hour' => ['activation' => 3], 'previous_hour' => ['activation' => 3]],
            // 1 + 1 + 1 = 3 across events+windows → not alerting
            'audit_log_swallow' => [
                'current_hour'  => ['a' => 1, 'b' => 1],
                'previous_hour' => ['a' => 1],
            ],
        ]);
        self::assertSame(['throttle'], DegradationAlerter::computeAlerting($snap, 5));
    }

    public function testMultipleAlertingSubsystemsReturnedSorted(): void
    {
        $snap = self::snapshot([
            'zeta'  => ['current_hour' => ['x' => 5], 'previous_hour' => []],
            'alpha' => ['current_hour' => ['x' => 9], 'previous_hour' => []],
        ]);
        self::assertSame(['alpha', 'zeta'], DegradationAlerter::computeAlerting($snap, 5));
    }

    // ── transitions (dedup driver) ──────────────────────────────────────

    public function testTransitionsDetectNewAndRecovered(): void
    {
        $t = DegradationAlerter::transitions(['a', 'b'], ['b', 'c']);
        self::assertSame(['a'], $t['newly'], 'a newly alerting');
        self::assertSame(['c'], $t['recovered'], 'c recovered');
    }

    public function testSteadyStateProducesNoTransitions(): void
    {
        // Still-alerting set unchanged → no alert (the anti-spam guarantee).
        $t = DegradationAlerter::transitions(['a', 'b'], ['a', 'b']);
        self::assertSame([], $t['newly']);
        self::assertSame([], $t['recovered']);
    }

    public function testFullRecoveryReportsAllPrevious(): void
    {
        $t = DegradationAlerter::transitions([], ['a', 'b']);
        self::assertSame([], $t['newly']);
        self::assertSame(['a', 'b'], $t['recovered']);
    }
}
