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

    /**
     * Subsystems whose degradation is P1 (page-now). The security/auth mail
     * paths are the trust anchor a hijacked-session attacker cannot suppress —
     * if THEY degrade, the out-of-band webhook must carry it ahead of the mail
     * path that may itself be the failure. Extend (don't replace) via the
     * `bcc_degradation_p1_subsystems` filter — e.g. bcc-trust adding a
     * "trust engine down" subsystem.
     *
     * @var list<string>
     */
    private const P1_SUBSYSTEMS = ['account_security_mail', 'auth_mail'];

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

    /**
     * Severity for one subsystem given the P1 set. Pure.
     *
     * @param list<string> $p1
     * @return 'P1'|'P2'
     */
    public static function severityFor(string $subsystem, array $p1): string
    {
        return in_array($subsystem, $p1, true) ? 'P1' : 'P2';
    }

    /**
     * Highest severity across a set of subsystems — 'P1' if any is P1. Pure.
     *
     * @param list<string> $subsystems
     * @param list<string> $p1
     * @return 'P1'|'P2'
     */
    public static function maxSeverity(array $subsystems, array $p1): string
    {
        foreach ($subsystems as $s) {
            if (in_array($s, $p1, true)) {
                return 'P1';
            }
        }
        return 'P2';
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
        $p1Set    = self::p1Subsystems();
        $severity = self::maxSeverity($subsystems, $p1Set);

        $severities = [];
        foreach ($subsystems as $s) {
            $severities[$s] = self::severityFor($s, $p1Set);
        }

        $site    = function_exists('home_url') ? (string) home_url('/') : '';
        $list    = implode(', ', $subsystems);
        $verb    = $kind === 'RECOVERED' ? 'recovered' : 'entered a sustained degraded state';
        $subject = sprintf('[BCC][%s] %s: %s', $severity, $kind, $list);
        $body    = sprintf(
            "<p><strong>Severity: %s</strong></p><p>The following subsystem(s) %s on %s:</p><ul>%s</ul><p>Snapshot:</p><pre>%s</pre>",
            $severity,
            $verb,
            $site !== '' ? htmlspecialchars($site) : 'this site',
            implode('', array_map(static fn(string $s): string => '<li>' . htmlspecialchars($s) . '</li>', $subsystems)),
            htmlspecialchars((string) wp_json_encode($snapshot))
        );

        // Webhook-PRIMARY for P1: send the out-of-band channel FIRST so a
        // slow/failing mail path (which may itself be the degraded subsystem)
        // cannot delay or mask a page-now alert. Both are independent
        // best-effort calls; ordering only matters for P1 urgency.
        if ($severity === 'P1') {
            self::webhook($kind, $subsystems, $site, $severity, $severities);
            self::email($subject, $body);
        } else {
            self::email($subject, $body);
            self::webhook($kind, $subsystems, $site, $severity, $severities);
        }
    }

    /**
     * The P1 subsystem set (constant default, extended via filter).
     *
     * @return list<string>
     */
    private static function p1Subsystems(): array
    {
        $p1 = self::P1_SUBSYSTEMS;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bcc_degradation_p1_subsystems', $p1);
            if (is_array($filtered)) {
                $p1 = array_values(array_filter($filtered, 'is_string'));
            }
        }
        return $p1;
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
     * @param list<string>          $subsystems
     * @param 'P1'|'P2'             $severity
     * @param array<string, string> $severities  subsystem => 'P1'|'P2'
     */
    private static function webhook(string $kind, array $subsystems, string $site, string $severity = 'P2', array $severities = []): void
    {
        $url = defined('BCC_DEGRADATION_ALERT_WEBHOOK') ? (string) constant('BCC_DEGRADATION_ALERT_WEBHOOK') : '';
        if ($url === '' || !function_exists('wp_remote_post')) {
            // A P1 alert with no out-of-band channel is itself a gap to record:
            // the operator is relying solely on a mail path that may be the
            // failing subsystem.
            if ($severity === 'P1' && $url === '' && class_exists(Logger::class)) {
                Logger::warning(
                    '[bcc-core] P1 degradation with no alert webhook configured',
                    ['subsystems' => $subsystems]
                );
            }
            return;
        }
        $payload = (string) wp_json_encode([
            'kind'       => $kind,
            'severity'   => $severity,
            'subsystems' => $subsystems,
            'severities' => $severities,
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
