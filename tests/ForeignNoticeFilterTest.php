<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\ForeignNoticeFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * On ?page=bcc-* screens, admin notices from non-BCC code are removed and
 * BCC's own are kept; on any other screen nothing is touched.
 */
#[CoversClass(ForeignNoticeFilter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ForeignNoticeFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/foreign-notice-stubs.php';
        $GLOBALS['__filters'] = [];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'bcc plugin'        => ['/srv/wp-content/plugins/bcc-trust/bcc-trust.php', true];
        yield 'bcc plugin nested' => ['C:\\site\\wp-content\\plugins\\bcc-core\\src\\Admin\\EnvBanner.php', true];
        yield 'bcc mu-plugin'     => ['/srv/wp-content/mu-plugins/bcc-demo-seeder.php', true];
        yield 'peepso'            => ['/srv/wp-content/plugins/peepso-core/classes/admin.php', false];
        yield 'wp core'           => ['/srv/wp-admin/includes/update.php', false];
        yield 'not a bcc- prefix' => ['/srv/wp-content/plugins/abcc-thing/x.php', false];
    }

    #[DataProvider('paths')]
    public function testIsBccFile(string $path, bool $expected): void
    {
        self::assertSame($expected, ForeignNoticeFilter::isBccFile($path));
    }

    public function testDefiningFileResolvesClosuresAndMethods(): void
    {
        self::assertSame(__FILE__, ForeignNoticeFilter::definingFile(static function (): void {}));
        self::assertSame(
            (new \ReflectionClass(ForeignNoticeFilter::class))->getFileName(),
            ForeignNoticeFilter::definingFile([ForeignNoticeFilter::class, 'filter'])
        );
        self::assertNull(ForeignNoticeFilter::definingFile('no_such_function_xyz'));
    }

    /**
     * @param array<int, mixed> $fns
     */
    private function seed(array $fns): void
    {
        $hook = new \stdClass();
        $hook->callbacks = [10 => []];
        foreach ($fns as $i => $fn) {
            $hook->callbacks[10]['cb' . $i] = ['function' => $fn, 'accepted_args' => 1];
        }
        $GLOBALS['wp_filter'] = ['admin_notices' => $hook];
    }

    public function testOnBccPageForeignNoticeIsRemovedAndUnattributableKept(): void
    {
        $_GET['page'] = 'bcc-system-health';
        // This test file is not under plugins/bcc-*, so its closure counts as foreign.
        $foreign = static function (): void {};
        $this->seed([$foreign, 'no_such_function_xyz']);

        ForeignNoticeFilter::filter();

        $left = array_column($GLOBALS['wp_filter']['admin_notices']->callbacks[10], 'function');
        self::assertNotContains($foreign, $left, 'foreign notice should be removed');
        self::assertContains('no_such_function_xyz', $left, 'unattributable callback is kept (fail open)');
    }

    public function testOtherScreensAreUntouched(): void
    {
        $_GET['page'] = 'peepso';
        $foreign = static function (): void {};
        $this->seed([$foreign]);

        ForeignNoticeFilter::filter();

        self::assertCount(1, $GLOBALS['wp_filter']['admin_notices']->callbacks[10]);
    }

    public function testEscapeHatchDisablesFiltering(): void
    {
        $_GET['page'] = 'bcc-cron';
        $GLOBALS['__filters']['bcc_admin_hide_foreign_notices'] = false;
        $foreign = static function (): void {};
        $this->seed([$foreign]);

        ForeignNoticeFilter::filter();

        self::assertCount(1, $GLOBALS['wp_filter']['admin_notices']->callbacks[10]);
    }
}
