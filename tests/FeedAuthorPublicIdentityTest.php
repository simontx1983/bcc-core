<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests;

use BCC\Core\Feed\ActivityFeedService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Feed author blocks must never show user_login: a missing handle is
 * 'member-{id}', and a display_name that is just the login (WordPress's
 * default for wp-admin / WP-CLI accounts) shows the handle instead.
 * Same rule as bcc-trust's PublicIdentity.
 *
 * Separate processes: the get_users / get_user_meta doubles are declared
 * in the BCC\Core\Feed namespace (so hydrateAuthors' unqualified calls
 * resolve to them) and must not leak into sibling suites.
 */
#[CoversMethod(ActivityFeedService::class, 'hydrateAuthors')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedAuthorPublicIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/object-cache-stubs.php';
        if (!\function_exists('BCC\\Core\\Feed\\get_users')) {
            eval(<<<'PHP'
                namespace BCC\Core\Feed;
                function get_users(array $args): array
                {
                    $out = [];
                    foreach ((array) ($args['include'] ?? []) as $id) {
                        if (isset($GLOBALS['__fa_users'][$id])) {
                            $out[] = (object) $GLOBALS['__fa_users'][$id];
                        }
                    }
                    return $out;
                }
                function get_user_meta(int $userId, string $key = '', bool $single = false)
                {
                    return $GLOBALS['__fa_meta'][$userId][$key] ?? '';
                }
                PHP);
        }

        $GLOBALS['__fa_users'] = [
            1 => ['ID' => 1, 'display_name' => 'Alice Smith', 'user_login' => 'u_alice'],
            2 => ['ID' => 2, 'display_name' => 'jsmith77',    'user_login' => 'jsmith77'],
            3 => ['ID' => 3, 'display_name' => '',            'user_login' => 'secretlogin'],
            4 => ['ID' => 4, 'display_name' => 'U_Dave',      'user_login' => 'u_dave'],
        ];
        $GLOBALS['__fa_meta'] = [
            1 => ['bcc_handle' => 'alice'],
            4 => ['bcc_handle' => 'dave'],
        ];
        // A cached '' ("no custom avatar") per author, so avatarUrlBulk
        // never recomputes through PeepSo.
        foreach (array_keys($GLOBALS['__fa_users']) as $id) {
            wp_cache_set('avatar:' . $id, '', 'bcc_core:user_media');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function hydrate(): array
    {
        /** @var ActivityFeedService $svc */
        $svc = (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod(ActivityFeedService::class, 'hydrateAuthors');
        /** @var array<int, array<string, mixed>> $out */
        $out = $m->invoke($svc, [1, 2, 3, 4], 0);
        return $out;
    }

    public function testRealHandleAndNameAreKept(): void
    {
        $a = self::hydrate()[1];
        self::assertSame('alice', $a['handle']);
        self::assertSame('Alice Smith', $a['display_name']);
    }

    public function testMissingHandleIsMemberIdNotLogin(): void
    {
        $out = self::hydrate();
        self::assertSame('member-2', $out[2]['handle']);
        self::assertSame('member-3', $out[3]['handle']);
    }

    public function testLoginNeverAppearsAsDisplayName(): void
    {
        $out = self::hydrate();
        self::assertSame('member-2', $out[2]['display_name']);
        self::assertSame('member-3', $out[3]['display_name']);
        // Case-insensitive: 'U_Dave' is the login 'u_dave'.
        self::assertSame('dave', $out[4]['display_name']);
    }

    public function testNoLoginInAnyAuthorBlock(): void
    {
        foreach (self::hydrate() as $id => $author) {
            $login = strtolower((string) $GLOBALS['__fa_users'][$id]['user_login']);
            self::assertNotSame($login, strtolower((string) $author['handle']), "handle of #$id");
            self::assertNotSame($login, strtolower((string) $author['display_name']), "name of #$id");
        }
    }
}
