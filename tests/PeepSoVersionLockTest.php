<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\Admin\PeepSoVersionLockNotice;
use BCC\Core\PeepSo\PeepSoVersionLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-176 (2): PeepSo core/add-on version lock, surfaced read-only.
 *
 * The comparison must agree with PeepSo::ready(): truncate both versions to
 * the first `version_lock` segments (1–3, anything else = 3), then compare.
 * On 2026-11-11 core 9.1.1.0 against add-ons 9.0.5.3 locked Groups, Pages
 * and Messages with no error anywhere.
 */
#[CoversClass(PeepSoVersionLock::class)]
#[CoversClass(PeepSoVersionLockNotice::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoVersionLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/peepso-version-lock-stubs.php';
        bcc_vl_world();
    }

    // ── the comparison (mirrors PeepSo::ready) ──────────────────────────

    /** @return array<string, array{string, string, int, bool}> */
    public static function pairs(): array
    {
        return [
            'equal'                                => ['9.0.5.3', '9.0.5.3', 3, true],
            'patch-only difference, lock 3'        => ['9.0.5.5', '9.0.5.3', 3, true],
            'minor difference, lock 3 (the incident)' => ['9.1.1.0', '9.0.5.3', 3, false],
            'third segment differs, lock 3'        => ['9.0.6.0', '9.0.5.3', 3, false],
            'third segment differs, lock 2'        => ['9.0.6.0', '9.0.5.3', 2, true],
            'minor differs, lock 2'                => ['9.1.0.0', '9.0.5.3', 2, false],
            'minor differs, lock 1'                => ['9.1.0.0', '9.0.5.3', 1, true],
            'major differs, lock 1'                => ['10.0.0.0', '9.0.5.3', 1, false],
            'shorter version string'               => ['9.0.5', '9.0.5.3', 3, true],
        ];
    }

    #[DataProvider('pairs')]
    public function testTheComparisonMatchesPeepSoReady(string $core, string $addon, int $lock, bool $ok): void
    {
        self::assertSame($ok, PeepSoVersionLock::matches($core, $addon, $lock));
    }

    /** @return array<string, array{mixed, int}> */
    public static function locks(): array
    {
        return [
            'int 1'        => [1, 1],
            'int 2'        => [2, 2],
            'string "2"'   => ['2', 2],
            'out of range' => [4, 3],
            'zero'         => [0, 3],
            'false (no PeepSo config)' => [false, 3],
            'null'         => [null, 3],
            'garbage'      => ['abc', 3],
        ];
    }

    #[DataProvider('locks')]
    public function testTheLockDepthDefaultsToThreeLikeReady(mixed $raw, int $expected): void
    {
        self::assertSame($expected, PeepSoVersionLock::normaliseLock($raw));
    }

    // ── the live read ───────────────────────────────────────────────────

    public function testTheIncidentNamesEveryLockedAddOn(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');

        $status = PeepSoVersionLock::currentStatus();

        self::assertNotNull($status);
        self::assertSame('9.1.1.0', $status['core_version']);
        self::assertSame(3, $status['lock']);
        self::assertSame(
            ['PeepSo Groups', 'PeepSo Messages', 'PeepSo Pages', 'PeepSo Photos'],
            array_column($status['mismatches'], 'name')
        );
    }

    public function testAPatchOnlyDifferenceIsClean(): void
    {
        // The local site as it stands: core 9.0.5.5, add-ons 9.0.5.3.
        bcc_vl_world('9.0.5.5', '9.0.5.3');

        self::assertSame([], PeepSoVersionLock::currentStatus()['mismatches'] ?? null);
    }

    public function testAnInactiveAddOnIsIgnored(): void
    {
        // Friends is installed at 8.0.0.0 but not active: PeepSo never
        // boots it, so it cannot be locked out.
        bcc_vl_world('9.0.5.5', '9.0.5.3');

        $status = PeepSoVersionLock::currentStatus();

        self::assertNotNull($status);
        self::assertNotContains('peepso-friends/peepsofriends.php', array_column($status['mismatches'], 'plugin'));
    }

    public function testAnActiveButMissingAddOnIsIgnored(): void
    {
        // Deleted from disk but still listed in active_plugins.
        bcc_vl_world('9.1.1.0', '9.1.1.0');
        $GLOBALS['__bcc_vl']['active'][] = 'peepso-friends-gone/gone.php';

        self::assertSame([], PeepSoVersionLock::currentStatus()['mismatches'] ?? null);
    }

    public function testNoActiveCoreMeansNothingToCompare(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');
        $GLOBALS['__bcc_vl']['active'] = array_values(array_diff($GLOBALS['__bcc_vl']['active'], ['peepso/peepso.php']));

        self::assertNull(PeepSoVersionLock::currentStatus());
    }

    public function testNetworkActivatedAddOnsCount(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');
        $GLOBALS['__bcc_vl']['active']    = ['peepso/peepso.php'];
        $GLOBALS['__bcc_vl']['multisite'] = true;
        $GLOBALS['__bcc_vl']['network']   = ['peepso-groups/groups.php' => 1];

        self::assertSame(['PeepSo Groups'], array_column(PeepSoVersionLock::currentStatus()['mismatches'] ?? [], 'name'));
    }

    public function testPeepSoOwnLockOptionAndCoreConstantAreUsed(): void
    {
        // A PeepSo class like the real one: the constant is what ready()
        // compares, and get_option_new('version_lock') is its lock depth.
        eval('class PeepSo { const PLUGIN_VERSION = "9.0.6.0"; public static function get_option_new($n) { return $n === "version_lock" ? 2 : false; } }');
        bcc_vl_world('header-ignored', '9.0.5.3');

        $status = PeepSoVersionLock::currentStatus();

        self::assertNotNull($status);
        self::assertSame('9.0.6.0', $status['core_version']);
        self::assertSame(2, $status['lock']);
        self::assertSame([], $status['mismatches'], '9.0 == 9.0 at lock 2');
    }

    // ── what the operator sees ──────────────────────────────────────────

    public function testTheNoticeNamesPluginsAndVersions(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');

        ob_start();
        PeepSoVersionLockNotice::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('VERSION LOCKED', $html);
        self::assertStringContainsString('PeepSo core is 9.1.1.0', $html);
        self::assertStringContainsString('PeepSo Groups 9.0.5.3', $html);
        self::assertStringContainsString('WP-CLI skips this check', $html);
    }

    public function testNoNoticeWhenClean(): void
    {
        bcc_vl_world('9.0.5.5', '9.0.5.3');

        ob_start();
        PeepSoVersionLockNotice::render();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testNoNoticeForUsersWhoCannotManagePlugins(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');
        $GLOBALS['__bcc_vl']['can'] = false;

        ob_start();
        PeepSoVersionLockNotice::render();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testSiteHealthIsCriticalOnAMismatchAndGoodOtherwise(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');
        $bad = PeepSoVersionLockNotice::siteHealthResult(PeepSoVersionLock::currentStatus(), false);
        self::assertSame('critical', $bad['status']);
        self::assertSame(PeepSoVersionLockNotice::TEST_ID, $bad['test']);
        self::assertStringContainsString('PeepSo Pages 9.0.5.3', (string) $bad['description']);

        bcc_vl_world('9.0.5.5', '9.0.5.3');
        self::assertSame('good', PeepSoVersionLockNotice::siteHealthResult(PeepSoVersionLock::currentStatus(), false)['status']);
        self::assertSame('good', PeepSoVersionLockNotice::siteHealthResult(null, false)['status'], 'no core: nothing to compare');
    }

    public function testTheUpdateBlockerIsMentionedOnlyWhenInstalled(): void
    {
        bcc_vl_world('9.1.1.0', '9.0.5.3');
        $status = PeepSoVersionLock::currentStatus();
        self::assertNotNull($status);

        self::assertStringContainsString('bcc-block-peepso-updates.php', PeepSoVersionLockNotice::detail($status, true));
        self::assertStringNotContainsString('bcc-block-peepso-updates.php', PeepSoVersionLockNotice::detail($status, false));
    }

    public function testTheSiteHealthTestIsRegisteredAsADirectTest(): void
    {
        $tests = PeepSoVersionLockNotice::registerSiteHealthTest(['direct' => ['other' => []], 'async' => []]);

        self::assertArrayHasKey('other', $tests['direct']);
        self::assertSame(
            [PeepSoVersionLockNotice::class, 'siteHealthTest'],
            $tests['direct'][PeepSoVersionLockNotice::TEST_ID]['test']
        );
    }
}
