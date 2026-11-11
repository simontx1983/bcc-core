<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\AdminMenu;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Menu layout contract:
 *  - developer pages register with an EMPTY parent (unlisted, URL still
 *    works) unless BCC_DEV_TOOLS is exactly `true`; alias pages always do;
 *  - each BCC menu is ordered by its slug list, and WP's auto-inserted
 *    duplicate parent item is dropped.
 * Separate processes: BCC_DEV_TOOLS is a constant.
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

        $GLOBALS['__filters'] = [
            'bcc_admin_dev_pages'    => ['bcc-developer', 'bcc-trust-debug'],
            'bcc_admin_hidden_pages' => ['bcc-wallets'],
        ];
    }

    public function testDevPageUnlistedWhenConstantUndefined(): void
    {
        self::assertSame('', AdminMenu::parentFor(AdminMenu::SYSTEM, 'bcc-developer'));
        self::assertSame(AdminMenu::SYSTEM, AdminMenu::parentFor(AdminMenu::SYSTEM, 'bcc-cron'));
    }

    public function testDevPageUnlistedUnlessStrictlyTrue(): void
    {
        define('BCC_DEV_TOOLS', '1');

        self::assertFalse(AdminMenu::devToolsEnabled());
        self::assertSame('', AdminMenu::parentFor(AdminMenu::SYSTEM, 'bcc-trust-debug'));
    }

    public function testDevPageListedWhenEnabledButAliasStaysUnlisted(): void
    {
        define('BCC_DEV_TOOLS', true);

        self::assertSame(AdminMenu::SYSTEM, AdminMenu::parentFor(AdminMenu::SYSTEM, 'bcc-developer'));
        self::assertSame('', AdminMenu::parentFor(AdminMenu::SYSTEM, 'bcc-wallets'));
    }

    public function testSortOrdersBySlugListAndDropsAutoInsertedDuplicate(): void
    {
        $GLOBALS['__filters']['bcc_admin_menu_order'] = [
            'bcc-notifications' => ['bcc-notifications', 'bcc-trust-moderation', 'bcc-disputes', 'bcc-trust-dashboard'],
        ];
        $GLOBALS['submenu'] = [
            'bcc-notifications' => [
                0 => ['BCC Moderation', 'manage_options', 'bcc-notifications'],   // WP auto-insert
                1 => ['Trust Analytics', 'manage_options', 'bcc-trust-dashboard'],
                2 => ['Notifications', 'manage_options', 'bcc-notifications'],
                3 => ['Extra', 'manage_options', 'bcc-something-else'],
                4 => ['Disputes', 'manage_options', 'bcc-disputes'],
                5 => ['Moderation', 'manage_options', 'bcc-trust-moderation'],
            ],
        ];

        AdminMenu::sortMenus();

        self::assertSame(
            ['Notifications', 'Moderation', 'Disputes', 'Trust Analytics', 'Extra'],
            array_column($GLOBALS['submenu']['bcc-notifications'], 0)
        );
    }
}
