<?php
/**
 * BccMailer — shared HTML email delivery for the BCC plugin ecosystem.
 *
 * Any BCC plugin that needs to send a transactional HTML email calls this
 * class. It owns the "how": Content-Type headers, wp_mail() dispatch,
 * failure logging, and DegradationMetrics recording. Callers own the
 * "what": subject line, HTML body, and the failure-event name they want
 * surfaced in /system/health.
 *
 * Constraints (load-bearing — mirror AccountSecurityMailer discipline):
 *
 *   - Never throws. Every send is wrapped in try/catch. The caller's path
 *     is never broken by a mail failure.
 *
 *   - Never retries. A failed wp_mail records a DegradationMetric and
 *     stops. Transactional emails are time-sensitive; a delayed retry
 *     arriving after the user has already given up is worse than silence.
 *
 *   - Callers are responsible for HTML content safety. The htmlBody
 *     parameter is passed to wp_mail verbatim — callers MUST
 *     htmlspecialchars() any user-supplied values before interpolation.
 *
 * Observability: failures record
 * DegradationMetrics::record(BccMailer::SUBSYSTEM, $failureEvent).
 * Register every failure-event string you use in the healthSnapshot call
 * in bcc-core.php so /system/health can surface sustained failures.
 *
 * @package BCC\Core\Mail
 * @since   1.0.9 (email-verification track)
 */

declare(strict_types=1);

namespace BCC\Core\Mail;

use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;

if (!defined('ABSPATH')) {
    exit;
}

final class BccMailer
{
    /** DegradationMetrics subsystem key. Register event names in bcc-core.php. */
    public const SUBSYSTEM = 'auth_mail';

    /**
     * Send an HTML email.
     *
     * Never throws. Never retries. If wp_mail() fails a DegradationMetric
     * is recorded under {@see BccMailer::SUBSYSTEM} / $failureEvent and
     * the failure is logged — the caller's execution path is not affected.
     *
     * @param string $to           Recipient address. Must be a valid email;
     *                             call silently returns if is_email() fails.
     * @param string $subject      Subject line.
     * @param string $htmlBody     Complete HTML document. Caller is responsible
     *                             for escaping any user-supplied values before
     *                             passing them in (htmlspecialchars, ENT_QUOTES).
     * @param string $failureEvent Stable identifier recorded in DegradationMetrics
     *                             on wp_mail failure. Must be listed in bcc-core.php
     *                             healthSnapshot so /system/health can bucket it.
     */
    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $failureEvent
    ): void {
        if ($to === '' || !is_email($to)) {
            return;
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from = self::fromHeader();
        if ($from !== '') {
            $headers[] = $from;
        }

        $sent = false;
        try {
            $sent = (bool) wp_mail($to, $subject, $htmlBody, $headers);
        } catch (\Throwable $e) {
            // wp_mail itself shouldn't throw, but PHPMailer can if a
            // filter mis-configures it. Treat any throwable as failure.
            Logger::error('[bcc-core] BccMailer wp_mail threw', [
                'event' => $failureEvent,
                'error' => $e->getMessage(),
            ]);
        }

        if (!$sent) {
            Logger::error('[bcc-core] BccMailer wp_mail failed', [
                'event' => $failureEvent,
            ]);
            DegradationMetrics::record(self::SUBSYSTEM, $failureEvent);
        }
    }

    /**
     * Build the From header using the WP admin_email and site name.
     * Mirrors AccountSecurityMailer::fromHeader() so sender identity
     * is consistent across all BCC outbound mail.
     */
    private static function fromHeader(): string
    {
        $email = get_option('admin_email');
        if (!is_string($email) || $email === '') {
            return '';
        }
        $name = get_bloginfo('name') ?: 'BCC';
        return sprintf('From: %s <%s>', $name, $email);
    }
}
