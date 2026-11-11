<?php

namespace BCC\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that decides whether a remote GIF URL may be stored and
 * rendered. GIFs are never re-hosted: the URL is persisted as-is and later
 * rendered as an <img> in every viewer's browser, so accepting a URL means
 * pointing every viewer at that host.
 *
 * The rule is a HOST check, never a substring check. PeepSo's own
 * validation (`strpos($url, 'giphy.com') !== false`, peepso/classes/
 * giphy.php:316) accepts `https://evil.example/t.gif?x=giphy.com`, which
 * is why BCC does not mirror it.
 *
 * Accepted: an absolute `https` URL whose host is exactly `giphy.com` or
 * ends in `.giphy.com` (media0.giphy.com, i.giphy.com, …), with no
 * userinfo and no explicit non-default port.
 *
 * Shared by bcc-core's PeepSoGifWriter (post GIFs) and bcc-trust's
 * CommentService (comment GIFs) so the two paths cannot drift.
 */
final class GiphyUrl
{
    private const HOST        = 'giphy.com';
    private const HOST_SUFFIX = '.giphy.com';

    public static function isAllowed(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        // `https://giphy.com@evil.example/` has host evil.example, which the
        // host check below already rejects. Refuse userinfo outright anyway:
        // a GIF URL has no legitimate use for credentials.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return $host === self::HOST || str_ends_with($host, self::HOST_SUFFIX);
    }
}
