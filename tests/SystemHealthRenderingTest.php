<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** The real renderer consumes a bounded in-memory REST transport. */
#[CoversClass(SystemHealthPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SystemHealthRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/env-contract-stubs.php';
        // Declare transport shims only in the isolated test process.
        if (!function_exists(__NAMESPACE__ . '\\rest_do_request')) {
            function rest_do_request(\WP_REST_Request $request): HealthPageReply|HealthPageFailure
            {
                return HealthRenderTransport::$response;
            }
            function is_wp_error(mixed $response): bool
            {
                return $response instanceof HealthPageFailure;
            }
            function current_user_can(string $capability): bool
            {
                return $capability === 'manage_options';
            }
            function esc_html__(string $text): string
            {
                return esc_html($text);
            }
            function admin_url(string $path): string
            {
                return 'https://example.test/wp-admin/' . $path;
            }
            function esc_url(string $url): string
            {
                return esc_attr($url);
            }
            function wp_json_encode(mixed $data, int $flags = 0): string|false
            {
                return json_encode($data, $flags);
            }
        }
    }

    private function render(mixed $body, int $status = 200): string
    {
        HealthRenderTransport::$response = new HealthPageReply($body, $status);
        return $this->capture();
    }

    private function capture(): string
    {
        ob_start();
        try {
            SystemHealthPage::render();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function testJobStatesAndUnsummarizedDiagnosticsStayVisibleAndRawResponseIsComplete(): void
    {
        // Same fields as bcc-trust CronHealthSnapshot::evaluate().
        $data = [
            'status' => 'degraded',
            'timestamp' => '2026-11-26T00:00:00Z',
            'plugins' => [
                'crons' => [
                    'summary' => ['total' => 3, 'ok' => 1, 'stale' => 1, 'pending' => 1, 'has_stale' => true],
                    'jobs' => [
                        'recent_job' => ['state' => 'ok', 'age_seconds' => 60, 'interval' => 'hourly', 'last_success_ts' => 1700000000, 'threshold_seconds' => 7200, 'description' => 'Recent work'],
                        'late_job' => ['state' => 'stale', 'age_seconds' => 10800, 'interval' => 'hourly'],
                        'new_job' => ['state' => 'pending', 'age_seconds' => null, 'interval' => 'daily'],
                    ],
                ],
                'degradation_metrics' => [
                    'any_active' => true,
                    'subsystems' => ['mail' => ['current_hour' => ['send_failed' => 2], 'previous_hour' => ['send_failed' => 1]]],
                ],
                'indexer' => ['status' => 'degraded', 'detail' => 'provider unavailable'],
                'another_plugin' => ['extra_field' => 'Retain me'],
            ],
        ];
        $html = $this->render($data);
        self::assertStringContainsString('bcc-badge--ok">OK', $html);
        self::assertStringContainsString('bcc-badge--bad">Stale', $html);
        self::assertStringContainsString('bcc-badge--warn">Pending', $html);
        self::assertStringContainsString('1m ago', $html);
        self::assertStringContainsString('3h ago', $html);
        self::assertStringContainsString('Not recorded', $html);
        self::assertStringContainsString('<details open style="margin-top:24px;">', $html);
        self::assertStringContainsString('provider unavailable', $html);
        self::assertStringContainsString('Retain me', $html);
        self::assertStringContainsString('Degradation metrics (current hour + previous hour)', $html);
        self::assertStringContainsString('send_failed', $html);
        self::assertSame(1, preg_match('/<summary[^>]*>Raw JSON<\/summary><pre[^>]*>(.*?)<\/pre>/s', $html, $matches));
        self::assertSame($data, json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMalformedJobsRemainUnknownOrStaleAndAllTextIsEscaped(): void
    {
        $html = $this->render(['status' => 'ok', 'plugins' => ['crons' => ['jobs' => [
            '<img src=x>' => ['state' => 'ok', 'age_seconds' => '60', 'description' => '<script>bad()</script>', 'interval' => '<b>hourly</b>'],
            'invalid_row' => 'unreadable',
            'late_job' => ['state' => 'stale', 'age_seconds' => -1, 'interval' => ''],
        ]]]]);
        self::assertSame(1, preg_match('/Scheduled job health.*?(<table.*?<\/table>)/s', $html, $matches));
        $table = $matches[1];
        self::assertStringNotContainsString('bcc-badge--ok', $table);
        self::assertStringContainsString('bcc-badge--warn">Unknown', $table);
        self::assertStringContainsString('bcc-badge--bad">Stale', $table);
        self::assertStringContainsString('&lt;img src=x&gt;', $table);
        self::assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $table);
        self::assertStringContainsString('&lt;b&gt;hourly&lt;/b&gt;', $table);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('invalid_row', $table);
        self::assertStringContainsString('Not recorded', $table);
    }

    public function testUnavailableJobListIsNotReportedAsHealthyAndItsDiagnosticsRemainAvailable(): void
    {
        $html = $this->render(['status' => 'ok', 'plugins' => ['crons' => ['jobs' => null, 'detail' => 'read unavailable']]]);
        self::assertStringContainsString('Scheduled job details are unavailable.', $html);
        self::assertStringContainsString('read unavailable', $html);
        self::assertStringContainsString('Raw JSON', $html);
    }

    public function testMalformedTransportShapeIsAnExplicitFailure(): void
    {
        foreach ([[], null, 'unavailable', ['status' => []]] as $body) {
            $html = $this->render($body);
            self::assertStringContainsString('notice-error', $html);
            self::assertStringContainsString('unexpected response shape', $html);
            self::assertStringNotContainsString('<table', $html);
        }
    }

    public function testUnknownStatusIsStillShownRatherThanReclassified(): void
    {
        $html = $this->render(['status' => 'unknown']);
        self::assertStringContainsString('UNKNOWN', $html);
        self::assertStringContainsString('Raw JSON', $html);
    }

    public function testRestFailuresAreEscapedAndNeverShowDefaultHealthRows(): void
    {
        $html = $this->render(['message' => '<b>Unavailable</b>'], 503);
        self::assertStringContainsString('&lt;b&gt;Unavailable&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<table', $html);
        $html = $this->render(['message' => ['unexpected']], 503);
        self::assertStringContainsString('HTTP 503', $html);
        HealthRenderTransport::$response = new HealthPageFailure('<script>Unavailable</script>');
        $html = $this->capture();
        self::assertStringContainsString('&lt;script&gt;Unavailable&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<table', $html);
    }
}

final class HealthRenderTransport
{
    public static HealthPageReply|HealthPageFailure $response;
}

final class HealthPageReply
{
    public function __construct(private mixed $body, private int $status) {}
    public function is_error(): bool { return $this->status >= 400; }
    public function get_data(): mixed { return $this->body; }
    public function get_status(): int { return $this->status; }
}

final class HealthPageFailure
{
    public function __construct(private string $message) {}
    public function get_error_message(): string { return $this->message; }
}
