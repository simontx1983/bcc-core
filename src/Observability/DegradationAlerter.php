<?php
/**
 * DegradationAlerter — turns the pull-only DegradationMetrics surface into a
 * push alert so a sustained degraded state reaches a human instead of sitting
 * in an hourly bucket until someone opens the dashboard.
 *
 * Design:
 *   - Reuses the canonical subsystem map via the `bcc_system_health` filter
 *     (no parallel copy of the map — §V no-parallel-systems).
 *   - "Alerting" = a subsystem whose summed current+previous-hour event counts
 *     cross a threshold (sustained, not a single blip).
 *   - De-duplicated: alerts ONCE on transition into the alerting set and ONCE
 *     on recovery (an "all clear"). State is a single option keyed by subsystem
 *     name, so a still-degraded subsystem doesn't re-alert every cron tick.
 *   - Push sink: BccMailer (operator email) + optional webhook. Both
 *     best-effort and non-blocking; unconfigured → no-op.
 *
 * The decision logic (computeAlerting / transitions) is pure and unit-tested;
 * evaluate() is the cron entry point that wires it to WordPress.
 *
 * @package BCC\Core\Observability
 */

declare(strict_types=1);

namespace BCC\Core\Observability;

use BCC\Core\Log\Logger;
use BCC\Core\Mail\BccMailer;

if (!defined('ABSPATH')) {
    exit;
}

final class DegradationAlerter
{
    public const CRON_HOOK    = 'bcc_core_degradation_alert_check';
    private const STATE_OPTION = 'bcc_core_degradation_alert_state';

    /** Default summed (current+previous hour) event count that trips an alert. */
    private const DEFAULT_THRESHOLD = 5;

    /** DegradationMetric event recorded when this mailer itself fails. */
    private const MAIL_EVENT_SUBSYSTEM = 'degradation_alert_mail';

    // ── Pure decision logic (unit-tested, no WordPress) ─────────────────────

    /**
     * Which subsystems are in an alerting state: those whose summed
     * current+previous-hour counts (across all their events) meet the threshold.
     *
     * @param array{subsystems?: array<string, array{current_hour?: array<string,int>, previous_hour?: array<string,int>}>} $snapshot
     *        DegradationMetrics::healthSnapshot() output.
     * @return list<string> sorted subsystem names
     */
    public static function computeAlerting(array $snapshot, int $threshold): array
    {
        $alerting = [];
        foreach (($snapshot['subsystems'] ?? []) as $subsystem => $windows) {
            if (!is_string($subsystem)) {
                continue;
            }
            $sum = array_sum($windows['current_hour'] ?? []) + array_sum($windows['previous_hour'] ?? []);
            if ($sum >= $threshold) {
                $alerting[] = $subsystem;
            }
        }
        sort($alerting);
        return $alerting;
    }

    /**
     * Transitions between the previous alerting set and the current one.
     *
     * @param list<string> $current
     * @param list<string> $previous
     * @return array{newly: list<string>, recovered: list<string>}
     */
    public static function transitions(array $current, array $previous): array
    {
        return [
            'newly'     => array_values(array_diff($current, $previous)),
            'recovered' => array_values(array_diff($previous, $current)),
        ];
    }

    // ── Cron entry point (WordPress wiring) ─────────────────────────────────

    public static function evaluate(): void
    {
        $health   = (array) apply_filters('bcc_system_health', []);
        $snapshot = is_array($health['degradation_metrics'] ?? null) ? $health['degradation_metrics'] : [];

        $threshold = defined('BCC_DEGRADATION_ALERT_THRESHOLD')
            ? max(1, (int) constant('BCC_DEGRADATION_ALERT_THRESHOLD'))
            : self::DEFAULT_THRESHOLD;

        $current  = self::computeAlerting($snapshot, $threshold);
        $previous = get_option(self::STATE_OPTION, []);
        $previous = is_array($previous) ? array_values(array_filter($previous, 'is_string')) : [];

        $t = self::transitions($current, $previous);

        if ($t['newly'] !== []) {
            self::dispatch('DEGRADED', $t['newly'], $snapshot);
        }
        if ($t['recovered'] !== []) {
            self::dispatch('RECOVERED', $t['recovered'], $snapshot);
        }

        // Persist the new state only if it changed — keeps the option write off
        // the hot path on steady-state ticks.
        if ($t['newly'] !== [] || $t['recovered'] !== []) {
            update_option(self::STATE_OPTION, $current, false);
        }
    }

    /**
     * @param 'DEGRADED'|'RECOVERED'                    $kind
     * @param list<string>                              $subsystems
     * @param array<string, mixed>                      $snapshot
     */
    private static function dispatch(string $kind, array $subsystems, array $snapshot): void
    {
        $site    = function_exists('home_url') ? (string) home_url('/') : '';
        $list    = implode(', ', $subsystems);
        $verb    = $kind === 'RECOVERED' ? 'recovered' : 'entered a sustained degraded state';
        $subject = sprintf('[BCC] %s: %s', $kind, $list);
        $body    = sprintf(
            "<p>The following subsystem(s) %s on %s:</p><ul>%s</ul><p>Snapshot:</p><pre>%s</pre>",
            $verb,
            $site !== '' ? htmlspecialchars($site) : 'this site',
            implode('', array_map(static fn(string $s): string => '<li>' . htmlspecialchars($s) . '</li>', $subsystems)),
            htmlspecialchars((string) wp_json_encode($snapshot))
        );

        self::email($subject, $body);
        self::webhook($kind, $subsystems, $site);
    }

    private static function email(string $subject, string $htmlBody): void
    {
        $to = defined('BCC_DEGRADATION_ALERT_EMAIL') && constant('BCC_DEGRADATION_ALERT_EMAIL') !== ''
            ? (string) constant('BCC_DEGRADATION_ALERT_EMAIL')
            : (function_exists('get_option') ? (string) get_option('admin_email', '') : '');

        if ($to === '') {
            return; // unconfigured → no-op
        }
        if (class_exists(BccMailer::class)) {
            BccMailer::send($to, $subject, $htmlBody, self::MAIL_EVENT_SUBSYSTEM);
        }
    }

    /**
     * @param list<string> $subsystems
     */
    private static function webhook(string $kind, array $subsystems, string $site): void
    {
        $url = defined('BCC_DEGRADATION_ALERT_WEBHOOK') ? (string) constant('BCC_DEGRADATION_ALERT_WEBHOOK') : '';
        if ($url === '' || !function_exists('wp_remote_post')) {
            return;
        }
        $payload = (string) wp_json_encode([
            'kind'       => $kind,
            'subsystems' => $subsystems,
            'site'       => $site,
        ]);
        $resp = wp_remote_post($url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => $payload,
        ]);
        if (function_exists('is_wp_error') && is_wp_error($resp) && class_exists(Logger::class)) {
            Logger::warning('[bcc-core] degradation alert webhook failed', ['url' => $url]);
        }
    }
}
