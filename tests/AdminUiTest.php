<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\AdminUi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AdminUi is the single badge / interval / relative-time helper every BCC
 * admin page renders through. The badge emits CLASSES only — the palette
 * lives in assets/css/bcc-admin.css — and must stay escaped.
 */
#[CoversClass(AdminUi::class)]
final class AdminUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/env-contract-stubs.php';
    }

    public function testBadgeEmitsToneClassAndNoInlineColour(): void
    {
        $html = AdminUi::badge('OK', AdminUi::OK);

        self::assertSame('<span class="bcc-badge bcc-badge--ok">OK</span>', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testBadgeEscapesLabel(): void
    {
        self::assertSame(
            '<span class="bcc-badge bcc-badge--bad">&lt;b&gt;x&lt;/b&gt;</span>',
            AdminUi::badge('<b>x</b>', AdminUi::BAD)
        );
    }

    public function testUnknownToneFallsBackToMuted(): void
    {
        self::assertStringContainsString('bcc-badge--muted', AdminUi::badge('x', '#46b450'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function intervals(): iterable
    {
        yield 'seconds'      => [45, '45s'];
        yield 'minute floor' => [119, '1m'];
        yield 'hours'        => [7200, '2h'];
        yield 'days'         => [3 * 86400 + 5, '3d'];
        yield 'negative'     => [-300, '5m'];
    }

    #[DataProvider('intervals')]
    public function testInterval(int $seconds, string $expected): void
    {
        self::assertSame($expected, AdminUi::interval($seconds));
    }

    public function testRelativeIsTwoWayAndHandlesMissingTimestamp(): void
    {
        $now = 1_700_000_000;

        self::assertSame('in 5m', AdminUi::relative($now + 300, $now));
        self::assertSame('2h ago', AdminUi::relative($now - 7200, $now));
        self::assertSame('never', AdminUi::relative(0, $now));
    }
}
