<?php

declare(strict_types=1);

namespace BCC\Core\Observability\Tests;

use BCC\Core\Observability\DegradationAlerter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-51 — the alert webhook URL (which carries its secret in the path) is
 * never logged.
 *
 * A failed webhook post used to log ['url' => $url]. Pinned against the REAL
 * DegradationAlerter::webhook() (a recording Logger stub, declared before
 * anything autoloads the real one) and the REAL Logger redaction:
 *   - a failed post logs the host and the WP_Error code — never the URL or
 *     any part of its path;
 *   - Logger fully redacts webhook-named context keys as a backstop, but
 *     keeps webhook_host.
 *
 * Subprocess-only (constants, and the Logger stub in the alerter cases).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WebhookUrlNotLoggedTest extends TestCase
{
    private const SECRET_PATH = '/services/T000/B000/XXXXsecretXXXX';
    private const URL         = 'https://hooks.example.test' . self::SECRET_PATH;

    private static function loadAlerterStubs(): void
    {
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger {
                public static array $warnings = [];
                public static function warning(string $m, array $c = []): void { self::$warnings[] = [$m, $c]; }
                public static function error(string $m, array $c = []): void { self::$warnings[] = [$m, $c]; }
                public static function info(string $m, array $c = []): void {}
            }');
        }
        if (!function_exists('wp_remote_post')) {
            eval('function wp_remote_post($url, $args = []) { return new \WP_Error("http_request_failed", "cURL error 28: timed out"); }');
        }
        if (!function_exists('is_wp_error')) {
            eval('function is_wp_error($t) { return $t instanceof \WP_Error; }');
        }
        if (!function_exists('wp_json_encode')) {
            eval('function wp_json_encode($d) { return json_encode($d); }');
        }
    }

    public function testAFailedPostLogsTheHostAndCodeNeverTheUrl(): void
    {
        self::loadAlerterStubs();
        define('BCC_DEGRADATION_ALERT_WEBHOOK', self::URL);

        $m = new \ReflectionMethod(DegradationAlerter::class, 'webhook');
        $m->invoke(null, 'trip', ['auth_throttle'], 'stage.example.test', 'P1', []);

        $logged = \BCC\Core\Log\Logger::$warnings;
        self::assertCount(1, $logged);
        self::assertSame('[bcc-core] degradation alert webhook failed', $logged[0][0]);
        self::assertSame(['webhook_host' => 'hooks.example.test', 'error_code' => 'http_request_failed'], $logged[0][1]);
        $flat = json_encode($logged, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('XXXXsecretXXXX', (string) $flat);
        self::assertStringNotContainsString(self::SECRET_PATH, (string) $flat);
    }

    public function testLoggerRedactsWebhookKeysButKeepsTheHost(): void
    {
        if (!function_exists('wp_salt')) {
            eval('function wp_salt($scheme = "auth") { return "unit-test-fixed-salt"; }');
        }
        $ref = new \ReflectionMethod(\BCC\Core\Log\Logger::class, 'redactSensitive');
        $out = $ref->invoke(null, [
            'webhook'       => self::URL,
            'webhook_url'   => self::URL,
            'alert_webhook' => self::URL,
            'webhook_host'  => 'hooks.example.test',
            'url'           => 'https://example.test/public',
        ]);

        self::assertSame('***REDACTED***', $out['webhook']);
        self::assertSame('***REDACTED***', $out['webhook_url']);
        self::assertSame('***REDACTED***', $out['alert_webhook']);
        self::assertSame('hooks.example.test', $out['webhook_host']);
        self::assertSame('https://example.test/public', $out['url'], 'a plain url key is not a webhook');
    }
}
