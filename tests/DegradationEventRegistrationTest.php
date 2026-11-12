<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every literal DegradationMetrics::record('subsystem', 'event') in src/ must
 * name an event registered in bcc-core.php's healthSnapshot() map.
 *
 * record() counts ANY name, but /system/health only displays the events the
 * map lists — so an unregistered event is counted and never shown. That is
 * how the eight F-272 group doors (one event per door) went invisible; they
 * now share the registered `group_writer_pending`.
 *
 * Calls whose event is a variable (the mailer's and Polkadot verifier's
 * failure events) are out of this scan's reach and are covered by their own
 * tests.
 */
final class DegradationEventRegistrationTest extends TestCase
{
    /** @return array<string, list<string>> subsystem => events */
    private static function registered(): array
    {
        $src   = (string) file_get_contents(dirname(__DIR__) . '/bcc-core.php');
        $start = strpos($src, 'DegradationMetrics::healthSnapshot([');
        self::assertNotFalse($start, 'healthSnapshot map not found');
        $end = strpos($src, "\n    ]);", $start);
        self::assertNotFalse($end);
        $map = substr($src, $start, $end - $start);

        $out = [];
        preg_match_all("/'([a-z0-9_]+)'\s*=>\s*\[([^\]]*)\]/", $map, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $subsystem, $body]) {
            preg_match_all("/'([a-z0-9_]+)'/", $body, $events);
            $out[$subsystem] = $events[1];
        }
        return $out;
    }

    /** @return list<array{subsystem: string, event: string, where: string}> */
    private static function literalCalls(): array
    {
        $root  = dirname(__DIR__) . '/src';
        $calls = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                if (preg_match("/DegradationMetrics::record\('([a-z0-9_]+)'(?:,\s*'([a-z0-9_]+)')?\)/", $line, $m) === 1) {
                    $calls[] = [
                        'subsystem' => $m[1],
                        'event'     => ($m[2] ?? '') !== '' ? $m[2] : 'activation',
                        'where'     => basename($file->getPathname()) . ':' . ($i + 1),
                    ];
                }
            }
        }
        return $calls;
    }

    public function testEveryRecordedEventIsShownOnTheHealthPage(): void
    {
        $map   = self::registered();
        $calls = self::literalCalls();

        self::assertGreaterThan(40, count($calls), 'the scan must actually find the record() calls');
        foreach ($calls as $call) {
            self::assertContains(
                $call['event'],
                $map[$call['subsystem']] ?? [],
                "{$call['where']}: '{$call['subsystem']}' / '{$call['event']}' is recorded but not in bcc-core.php's healthSnapshot map, so /system/health never shows it"
            );
        }
    }

    /**
     * Calls this scan cannot read (a variable event, or a call split over
     * lines) would silently escape the registration check. Only the two
     * known variable-event call sites are allowed; any other unreadable call
     * fails here, so it must be written as a one-line literal (or added to
     * this list with its own registration test).
     */
    public function testEveryRecordCallIsReadableByTheScan(): void
    {
        $allowed = [
            'BccMailer.php'                 => true, // record(self::SUBSYSTEM, $failureEvent) — own tests
            'PolkadotSignatureVerifier.php' => true, // record('polkadot_verify', $event) — own tests
        ];
        $root = dirname(__DIR__) . '/src';
        $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                $code = ltrim($line);
                if (!str_contains($code, 'DegradationMetrics::record(')
                    || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue; // not a call, or a docblock/comment mention
                }
                $seen++;
                $readable = preg_match("/DegradationMetrics::record\('([a-z0-9_]+)'(?:,\s*'([a-z0-9_]+)')?\)/", $line) === 1;
                self::assertTrue(
                    $readable || isset($allowed[basename($file->getPathname())]),
                    basename($file->getPathname()) . ':' . ($i + 1) . ': a record() call the registration scan cannot read — use a one-line literal subsystem and event'
                );
            }
        }
        self::assertGreaterThan(40, $seen);
    }

    public function testTheGroupDoorsShareOneRegisteredEvent(): void
    {
        self::assertContains('group_writer_pending', self::registered()['peepso_absence'] ?? []);

        $writer = (string) file_get_contents(dirname(__DIR__) . '/src/PeepSo/PeepSoGroupWriter.php');
        self::assertStringNotContainsString("'group_writer_' . \$op", $writer, 'a per-door event name is never registered');
    }
}
