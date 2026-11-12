<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

use BCC\Core\PeepSo\PeepSoVersionLock;

/**
 * F-176 (2): surfaces a PeepSo core/add-on version mismatch, read-only.
 *
 * Two places, both from {@see PeepSoVersionLock::currentStatus()}:
 *  - an error notice on every wp-admin screen, for users who can manage
 *    plugins (the condition breaks Groups, Pages and Messages site-wide);
 *  - a Site Health test (Tools → Site Health), critical on a mismatch.
 *
 * No logging: the notice and the test ARE the signal, and a per-request
 * log line would repeat on every admin page view. Nothing is repaired,
 * deactivated or updated.
 */
final class PeepSoVersionLockNotice
{
    public const TEST_ID = 'bcc_peepso_version_lock';

    /** Optional must-use plugin that blocks PeepSo core auto-updates (F-176 part 1, installed per host). */
    private const UPDATE_BLOCKER = 'bcc-block-peepso-updates.php';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']);
    }

    public static function render(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $status = PeepSoVersionLock::currentStatus();
        if ($status === null || $status['mismatches'] === []) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
            esc_html(self::headline($status)),
            esc_html(self::detail($status, self::updateBlockerInstalled()))
        );
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function registerSiteHealthTest(array $tests): array
    {
        $direct = isset($tests['direct']) && is_array($tests['direct']) ? $tests['direct'] : [];
        $direct[self::TEST_ID] = [
            'label' => 'PeepSo add-on versions match PeepSo core',
            'test'  => [self::class, 'siteHealthTest'],
        ];
        $tests['direct'] = $direct;

        return $tests;
    }

    /** @return array<string, mixed> */
    public static function siteHealthTest(): array
    {
        return self::siteHealthResult(PeepSoVersionLock::currentStatus(), self::updateBlockerInstalled());
    }

    /**
     * Pure: the Site Health result for a status.
     *
     * @param array{core_version: string, lock: int, mismatches: list<array{plugin: string, name: string, version: string}>}|null $status
     * @return array<string, mixed>
     */
    public static function siteHealthResult(?array $status, bool $blockerInstalled): array
    {
        $result = [
            'test'        => self::TEST_ID,
            'label'       => 'PeepSo add-on versions match PeepSo core',
            'status'      => 'good',
            'badge'       => ['label' => 'BCC', 'color' => 'blue'],
            'description' => '',
            'actions'     => '',
        ];

        if ($status === null) {
            $result['description'] = '<p>' . esc_html('PeepSo core is not active, so there is nothing to compare.') . '</p>';
            return $result;
        }

        if ($status['mismatches'] === []) {
            $result['description'] = '<p>' . esc_html(sprintf(
                'Every active PeepSo add-on matches PeepSo core %s to %d version segment(s).',
                $status['core_version'],
                $status['lock']
            )) . '</p>';
            return $result;
        }

        $result['status']      = 'critical';
        $result['label']       = self::headline($status);
        $result['badge']       = ['label' => 'BCC', 'color' => 'red'];
        $result['description'] = '<p>' . esc_html(self::detail($status, $blockerInstalled)) . '</p>';

        return $result;
    }

    /** @param array{core_version: string, lock: int, mismatches: list<array{plugin: string, name: string, version: string}>} $status */
    public static function headline(array $status): string
    {
        $names = array_map(static fn (array $m): string => $m['name'], $status['mismatches']);

        return sprintf('PeepSo add-ons are VERSION LOCKED and switched off: %s', implode(', ', $names));
    }

    /** @param array{core_version: string, lock: int, mismatches: list<array{plugin: string, name: string, version: string}>} $status */
    public static function detail(array $status, bool $blockerInstalled): string
    {
        $parts = array_map(
            static fn (array $m): string => sprintf('%s %s', $m['name'], $m['version']),
            $status['mismatches']
        );

        $text = sprintf(
            'PeepSo core is %1$s, but %2$s. PeepSo only boots an add-on whose first %3$d version segment(s) match core, '
            . 'so on every web request these add-ons register nothing (no error is shown). '
            . 'Bring core and add-ons to the same version. WP-CLI skips this check, so CLI probes still look healthy.',
            $status['core_version'],
            implode(', ', $parts),
            $status['lock']
        );

        if ($blockerInstalled) {
            $text .= ' Note: the must-use plugin ' . self::UPDATE_BLOCKER . ' blocks PeepSo core auto-updates on this site.';
        }

        return $text;
    }

    private static function updateBlockerInstalled(): bool
    {
        return defined('WPMU_PLUGIN_DIR') && is_file(WPMU_PLUGIN_DIR . '/' . self::UPDATE_BLOCKER);
    }
}
