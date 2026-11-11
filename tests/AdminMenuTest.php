<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\AdminMenu;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * BCC_DEV_TOOLS gate: developer pages leave the MENU unless the constant is
 * exactly `true`; always-hidden alias pages leave it regardless. Each case
 * runs in its own process because BCC_DEV_TOOLS is a constant.
 */
#[CoversClass(AdminMenu::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/Stubs/admin-menu-stubs.php';

        $GLOBALS['submenu'] = [
            'bcc-system-health' => [
                0  => ['Health', 'manage_options', 'bcc-system-health'],
                30 => ['Audit Log', 'manage_options', 'bcc-sessions'],
                31 => ['Wallets', 'manage_options', 'bcc-wallets'],
                80 => ['Developer', 'manage_options', 'bcc-developer'],
                81 => ['Debug', 'manage_options', 'bcc-trust-debug'],
            ],
        ];
        $GLOBALS['__filters'] = [
            'bcc_admin_dev_pages'    => ['bcc-developer', 'bcc-trust-debug'],
            'bcc_admin_hidden_pages' => ['bcc-wallets'],
        ];
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item[2],
            $GLOBALS['submenu']['bcc-system-health']
        ));
    }

    public function testDevPagesHiddenWhenConstantUndefined(): void
    {
        AdminMenu::pruneMenu();

        self::assertSame(['bcc-system-health', 'bcc-sessions'], $this->slugs());
    }

    public function testDevPagesHiddenUnlessStrictlyTrue(): void
    {
        define('BCC_DEV_TOOLS', '1');

        AdminMenu::pruneMenu();

        self::assertFalse(AdminMenu::devToolsEnabled());
        self::assertSame(['bcc-system-health', 'bcc-sessions'], $this->slugs());
    }

    public function testDevPagesListedWhenEnabledButAliasStaysHidden(): void
    {
        define('BCC_DEV_TOOLS', true);

        AdminMenu::pruneMenu();

        self::assertSame(
            ['bcc-system-health', 'bcc-sessions', 'bcc-developer', 'bcc-trust-debug'],
            $this->slugs()
        );
    }
}
