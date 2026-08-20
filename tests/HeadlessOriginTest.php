<?php

declare(strict_types=1);

namespace BCC\Core\Support\Tests;

use BCC\Core\Support\HeadlessOrigin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The home→site origin rebase, shared by the `rest_url` filter in
 * bcc-core.php and by PeepSoMediaCache.
 *
 * Under the headless split WP_HOME (the apex, served by Next.js) and
 * WP_SITEURL (cms.*) differ, and anything WordPress derives from
 * home_url() addresses a host that serves neither /wp-json nor
 * /wp-content.
 */
#[CoversClass(HeadlessOrigin::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeadlessOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/site-url-stubs.php';
        \BccTestSiteUrls::reset();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rebases(): iterable
    {
        yield 'front-door asset moves to WordPress' => [
            'https://bluecollarcrypto.io/wp-content/peepso/avatars-svg/abc.svg',
            'https://cms.bluecollarcrypto.io/wp-content/peepso/avatars-svg/abc.svg',
        ];
        yield 'REST URL moves to WordPress' => [
            'https://bluecollarcrypto.io/wp-json/bcc/v1/feed/hot',
            'https://cms.bluecollarcrypto.io/wp-json/bcc/v1/feed/hot',
        ];
        yield 'already correct is untouched' => [
            'https://cms.bluecollarcrypto.io/wp-content/peepso/users/37/avatar-full.jpg',
            'https://cms.bluecollarcrypto.io/wp-content/peepso/users/37/avatar-full.jpg',
        ];
        yield 'third-party host is untouched' => [
            'https://www.gravatar.com/avatar/abc?s=160&r=g',
            'https://www.gravatar.com/avatar/abc?s=160&r=g',
        ];
        yield 'query preserved' => [
            'https://bluecollarcrypto.io/wp-content/x.jpg?mt=123',
            'https://cms.bluecollarcrypto.io/wp-content/x.jpg?mt=123',
        ];
        yield 'fragment preserved' => [
            'https://bluecollarcrypto.io/a#frag',
            'https://cms.bluecollarcrypto.io/a#frag',
        ];
        yield 'bare origin' => [
            'https://bluecollarcrypto.io',
            'https://cms.bluecollarcrypto.io',
        ];
        yield 'host case is not significant' => [
            'https://BlueCollarCrypto.IO/wp-content/x.jpg',
            'https://cms.bluecollarcrypto.io/wp-content/x.jpg',
        ];
        yield 'empty string' => ['', ''];
        yield 'relative path is left alone' => [
            '/wp-content/peepso/avatars-svg/abc.svg',
            '/wp-content/peepso/avatars-svg/abc.svg',
        ];

        // ── The boundary cases ───────────────────────────────────────
        // A bare prefix test matches these too. Rewriting them would
        // mint a URL on a host we do not own and hand it back as ours.
        yield 'suffixed lookalike host is rejected' => [
            'https://bluecollarcrypto.io.evil.test/wp-content/x.jpg',
            'https://bluecollarcrypto.io.evil.test/wp-content/x.jpg',
        ];
        yield 'hyphen-suffixed lookalike host is rejected' => [
            'https://bluecollarcrypto.io-evil.test/x',
            'https://bluecollarcrypto.io-evil.test/x',
        ];
        yield 'prefixed lookalike host is rejected' => [
            'https://notbluecollarcrypto.io/wp-content/x.jpg',
            'https://notbluecollarcrypto.io/wp-content/x.jpg',
        ];
        yield 'scheme mismatch is left alone' => [
            'http://bluecollarcrypto.io/wp-content/x.jpg',
            'http://bluecollarcrypto.io/wp-content/x.jpg',
        ];
    }

    #[DataProvider('rebases')]
    public function testToWordPress(string $input, string $expected): void
    {
        self::assertSame($expected, HeadlessOrigin::toWordPress($input));
    }

    /**
     * The whole rule must vanish on a conventional install, so this can
     * ship without an environment gate.
     */
    public function testUnsplitInstallIsUntouched(): void
    {
        \BccTestSiteUrls::unsplit('https://example.test');

        foreach ([
            'https://example.test/wp-content/x.jpg',
            'https://example.test/wp-json/bcc/v1/feed/hot',
            'https://other.test/x',
        ] as $url) {
            self::assertSame($url, HeadlessOrigin::toWordPress($url));
        }
    }

    public function testLocalDevPortIsPartOfTheOrigin(): void
    {
        \BccTestSiteUrls::$home = 'http://bcc.local:3000';
        \BccTestSiteUrls::$site = 'http://bcc.local:10003';

        self::assertSame(
            'http://bcc.local:10003/wp-content/x.jpg',
            HeadlessOrigin::toWordPress('http://bcc.local:3000/wp-content/x.jpg')
        );

        // Same host, different port — a distinct origin, so untouched.
        self::assertSame(
            'http://bcc.local:9999/wp-content/x.jpg',
            HeadlessOrigin::toWordPress('http://bcc.local:9999/wp-content/x.jpg')
        );
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function origins(): iterable
    {
        yield 'scheme and host'  => ['https://example.test/a/b?c=1', 'https://example.test'];
        yield 'port retained'    => ['http://example.test:8080/a', 'http://example.test:8080'];
        yield 'lowercased'       => ['HTTPS://Example.TEST/a', 'https://example.test'];
        yield 'no scheme'        => ['example.test/a', null];
        yield 'path only'        => ['/wp-content/x.jpg', null];
        yield 'empty'            => ['', null];
    }

    #[DataProvider('origins')]
    public function testOriginOf(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, HeadlessOrigin::originOf($candidate));
    }
}
