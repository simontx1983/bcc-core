<?php
/**
 * RequestContext — request-scoped correlation id (Phase 4c ops-visibility).
 *
 * One id per request/process, shared by every Logger line and the REST
 * response (emitted as the `X-Request-Id` header and the existing
 * `_meta.request_id` envelope field). Lives in bcc-core because the Logger
 * lives here and bcc-trust (the Envelope) depends on bcc-core, not vice versa.
 *
 * Bound at the REST boundary by a rest_pre_dispatch filter: a client-supplied
 * `X-BCC-Request-Id` is adopted (sanitised) so the frontend can originate the
 * id and correlate across the tier; otherwise one is minted. Outside a REST
 * request (cron/CLI) it lazily mints on first log call, giving each worker run
 * a correlatable id too.
 *
 * @package BCC\Core\Http
 * @since Phase 4c ops-visibility (2026-06-25)
 */

declare(strict_types=1);

namespace BCC\Core\Http;

if (!defined('ABSPATH')) {
    exit;
}

final class RequestContext
{
    private static ?string $requestId = null;

    /** The id for this request/process; lazily minted on first access. */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            try {
                self::$requestId = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                self::$requestId = substr(md5(uniqid('', true)), 0, 16);
            }
        }
        return self::$requestId;
    }

    /**
     * Adopt a client-supplied id (sanitised). Returns false if it sanitises to
     * empty, so the caller can fall back to minting.
     */
    public static function setRequestId(string $id): bool
    {
        $clean = self::sanitize($id);
        if ($clean === '') {
            return false;
        }
        self::$requestId = $clean;
        return true;
    }

    public static function hasRequestId(): bool
    {
        return self::$requestId !== null;
    }

    /** Test seam — clears the request-scoped id. */
    public static function reset(): void
    {
        self::$requestId = null;
    }

    /**
     * Charset-restricted, length-bounded id. A malicious `X-BCC-Request-Id`
     * must not be able to inject control characters into log lines or forge
     * entries, so only safe id characters survive and the length is capped.
     */
    private static function sanitize(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.\-]/', '', $id) ?? '';
        return substr($clean, 0, 64);
    }
}
