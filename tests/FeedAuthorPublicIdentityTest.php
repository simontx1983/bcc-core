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

    /**
     * The `$wpdb` the name inputs are read through (UserMetaRepository's
     * guarded read), served from `__fa_meta`. Any other statement finds
     * nothing, so a signed-in viewer's follow-graph read follows nobody.
     */
    private static function db(string ...$modes): \BCC\Core\Tests\Stubs\UserMetaWpdb
    {
        require_once __DIR__ . '/Stubs/usermeta-wpdb.php';
        $db        = new \BCC\Core\Tests\Stubs\UserMetaWpdb('__fa_meta');
        $db->modes = array_values($modes);
        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    /** @return array<int, array<string, mixed>> */
    private static function hydrate(int $viewerId = 0): array
    {
        if (!(($GLOBALS['wpdb'] ?? null) instanceof \BCC\Core\Tests\Stubs\UserMetaWpdb)) {
            self::db();
        }
        /** @var ActivityFeedService $svc */
        $svc = (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        if ($viewerId > 0) {
            // A signed-in viewer makes hydrateAuthors ask the follow graph;
            // give it a real one over the $wpdb double (follows nobody), so
            // these tests isolate the name rule.
            $graph = (new ReflectionClass(\BCC\Core\PeepSo\PeepSoGraphService::class))->newInstanceWithoutConstructor();
            $prop  = new \ReflectionProperty(ActivityFeedService::class, 'graph');
            $prop->setValue($svc, $graph);
        }
        $m   = new ReflectionMethod(ActivityFeedService::class, 'hydrateAuthors');
        /** @var array<int, array<string, mixed>> $out */
        $out = $m->invoke($svc, [1, 2, 3, 4], $viewerId);
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
    // F-28: "Hide my real name" applies to feed authors too.

    public function testAHiddenRealNameShowsAsHandleToOthers(): void
    {
        $GLOBALS['__fa_meta'][1]['bcc_privacy_real_name_hidden'] = '1';
        self::assertSame('@alice', self::hydrate(99)[1]['display_name']);
        self::assertSame('@alice', self::hydrate(0)[1]['display_name'], 'anonymous viewer');
    }

    public function testTheAuthorStillSeesTheirOwnName(): void
    {
        $GLOBALS['__fa_meta'][1]['bcc_privacy_real_name_hidden'] = '1';
        self::assertSame('Alice Smith', self::hydrate(1)[1]['display_name']);
    }

    public function testAnUnsetOrFalseFlagShowsTheName(): void
    {
        $GLOBALS['__fa_meta'][1]['bcc_privacy_real_name_hidden'] = '0';
        self::assertSame('Alice Smith', self::hydrate(99)[1]['display_name']);
    }

    public function testDuplicateFlagRowsTakeTheStrictest(): void
    {
        $GLOBALS['__fa_meta'][1]['bcc_privacy_real_name_hidden'] = ['0', '1'];
        self::assertSame('@alice', self::hydrate(99)[1]['display_name']);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'a driver error'     => ['error'];
        yield 'the pre-flush bail' => ['preflush'];
    }

    /**
     * Rationale: M3 (private tracker). A batch whose name inputs cannot be
     * read shows every other viewer each author's @handle, and counts the
     * batch so a shared cache can decline to keep the page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable')]
    public function testAnUnreadableBatchShowsHandlesAndIsCounted(string $mode): void
    {
        $GLOBALS['__fa_meta'][1]['bcc_privacy_real_name_hidden'] = '0';
        $db              = self::db($mode);
        $db->last_result = [(object) ['user_id' => '1', 'meta_key' => 'bcc_privacy_real_name_hidden', 'meta_value' => '0']];
        $before          = ActivityFeedService::authorReadFailures();

        $out = self::hydrate(99);

        self::assertSame('@alice', $out[1]['display_name'], 'the handle comes from the primed cache');
        self::assertSame('@member-2', $out[2]['display_name']);
        self::assertSame('@dave', $out[4]['display_name']);
        self::assertSame($before + 1, ActivityFeedService::authorReadFailures());
    }

    public function testAnUnreadableBatchStillShowsTheAuthorTheirOwnName(): void
    {
        self::db('error');
        self::assertSame('Alice Smith', self::hydrate(1)[1]['display_name']);
    }

    public function testATrustedBatchIsNotCounted(): void
    {
        $before = ActivityFeedService::authorReadFailures();
        self::hydrate(99);
        self::assertSame($before, ActivityFeedService::authorReadFailures());
    }

    public function testOneGuardedReadServesTheWholeBatch(): void
    {
        $db = self::db();
        self::hydrate(0);
        self::assertSame(1, $db->metaQueries, 'handle and flag for every author, one query');
    }
}
