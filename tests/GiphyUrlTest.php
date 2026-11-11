<?php

declare(strict_types=1);

namespace BCC\Core\Security\Tests;

use BCC\Core\Security\GiphyUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GiphyUrl::isAllowed — the host check behind every stored GIF URL.
 *
 * ⚠ The thing that must not happen: a URL that merely CONTAINS
 * "giphy.com" is accepted. The previous writer check was
 * `strpos($url, 'giphy.com') !== false`, so
 * https://evil.example/t.gif?x=giphy.com was stored and rendered as an
 * <img> for every viewer.
 */
#[CoversClass(GiphyUrl::class)]
final class GiphyUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'apex'                  => ['https://giphy.com/gifs/abc'];
        yield 'media subdomain'       => ['https://media0.giphy.com/media/abc/giphy.gif'];
        yield 'i. subdomain'          => ['https://i.giphy.com/abc.webp'];
        yield 'uppercase host'        => ['https://MEDIA.GIPHY.COM/media/abc/giphy.gif'];
        yield 'explicit 443'          => ['https://media.giphy.com:443/media/abc/giphy.gif'];
        yield 'surrounding whitespace' => ["  https://media.giphy.com/media/abc/giphy.gif\n"];
        yield 'query string'          => ['https://media.giphy.com/media/abc/giphy.gif?cid=1&rid=giphy.gif'];
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        // The reported bypass and its relatives.
        yield 'substring in query'      => ['https://evil.example/t.gif?x=giphy.com'];
        yield 'substring in path'       => ['https://evil.example/giphy.com/t.gif'];
        yield 'substring in fragment'   => ['https://evil.example/t.gif#giphy.com'];
        yield 'lookalike suffix'        => ['https://notgiphy.com/t.gif'];
        yield 'giphy as a subdomain'    => ['https://giphy.com.evil.example/t.gif'];
        yield 'userinfo trick'          => ['https://giphy.com@evil.example/t.gif'];
        yield 'userinfo on giphy host'  => ['https://user:pass@media.giphy.com/t.gif'];
        // Scheme.
        yield 'plain http'              => ['http://media.giphy.com/media/abc/giphy.gif'];
        yield 'javascript scheme'       => ['javascript:alert(1)//giphy.com'];
        yield 'data uri'                => ['data:image/gif;base64,R0lGODlh//giphy.com'];
        yield 'protocol-relative'       => ['//media.giphy.com/media/abc/giphy.gif'];
        // Port and shape.
        yield 'non-default port'        => ['https://media.giphy.com:8443/t.gif'];
        yield 'empty'                   => [''];
        yield 'whitespace only'         => ['   '];
        yield 'not a url'               => ['giphy.com'];
        yield 'backslash host trick'    => ['https://evil.example\\@giphy.com/t.gif'];
    }

    #[DataProvider('allowed')]
    public function testAllowsHttpsGiphyHosts(string $url): void
    {
        self::assertTrue(GiphyUrl::isAllowed($url), $url);
    }

    #[DataProvider('rejected')]
    public function testRejectsEverythingElse(string $url): void
    {
        self::assertFalse(GiphyUrl::isAllowed($url), $url);
    }
}
