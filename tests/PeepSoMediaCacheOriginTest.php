<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoMediaCache;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Post-cutover regression: avatar URLs must resolve on the origin that
 * actually serves /wp-content.
 *
 * ## The bug this pins
 *
 * PeepSo generates a name-based SVG placeholder for anyone without an
 * uploaded avatar, and — unlike a real avatar, which it recomputes on
 * every call — it PERSISTS the finished URL into usermeta
 * (`peepso_name_based_avatar_url`) and returns that string verbatim
 * forever after. Rows written before the headless cutover were frozen on
 * the apex, which is now the Next.js app: it serves no /wp-content and
 * answers 403.
 *
 * Live on production before the fix, one feed page carried both:
 *
 *   17x  https://cms.bluecollarcrypto.io/wp-content/peepso/users/…    200
 *    6x  https://bluecollarcrypto.io/wp-content/peepso/avatars-svg/…  403
 *
 * Because the bad value is persisted DATA rather than something
 * computed, the repair has to happen on read — which also means the
 * copies already sitting in this cache are repaired by the same pass,
 * with no flush and no usermeta migration.
 */
#[CoversMethod(PeepSoMediaCache::class, 'avatarUrl')]
#[CoversMethod(PeepSoMediaCache::class, 'avatarUrlBulk')]
#[CoversMethod(PeepSoMediaCache::class, 'coverPhotoUrl')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoMediaCacheOriginTest extends TestCase
{
    private const FROZEN = 'https://bluecollarcrypto.io/wp-content/peepso/avatars-svg/abc.svg';
    private const FIXED  = 'https://cms.bluecollarcrypto.io/wp-content/peepso/avatars-svg/abc.svg';
    private const REAL   = 'https://cms.bluecollarcrypto.io/wp-content/peepso/users/37/avatar-full.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/object-cache-stubs.php';
        require_once __DIR__ . '/Stubs/site-url-stubs.php';
        \BccTestPersistentCache::reset();
        \BccTestSiteUrls::reset();
    }

    /** Seed the cache the way a pre-fix request would have left it. */
    private static function poison(int $userId, string $url): void
    {
        wp_cache_set('avatar:' . $userId, $url, 'bcc_core:user_media', 3600);
    }

    // ── The regression ───────────────────────────────────────────────

    public function testFrozenApexUrlIsRepairedOnRead(): void
    {
        self::poison(37, self::FROZEN);

        self::assertSame(self::FIXED, PeepSoMediaCache::avatarUrl(37));
    }

    /**
     * The point of repairing on read rather than on compute: entries
     * already poisoned before this shipped must heal without a flush.
     */
    public function testRepairNeedsNoCacheFlush(): void
    {
        self::poison(37, self::FROZEN);

        PeepSoMediaCache::avatarUrl(37);

        // Still poisoned in the store — nothing rewrote it…
        self::assertSame(
            self::FROZEN,
            \BccTestPersistentCache::raw('avatar:37', 'bcc_core:user_media')
        );
        // …yet every read comes back repaired.
        self::assertSame(self::FIXED, PeepSoMediaCache::avatarUrl(37));
    }

    public function testCorrectUrlSurvivesUntouched(): void
    {
        self::poison(37, self::REAL);

        self::assertSame(self::REAL, PeepSoMediaCache::avatarUrl(37));
    }

    public function testEmptyStaysEmpty(): void
    {
        // '' is a valid cached value meaning "no custom avatar" — it must
        // not become an origin.
        self::poison(37, '');

        self::assertSame('', PeepSoMediaCache::avatarUrl(37));
    }

    public function testGravatarIsNotRehosted(): void
    {
        $gravatar = 'https://www.gravatar.com/avatar/d41d8cd98f?s=160&r=g';
        self::poison(37, $gravatar);

        self::assertSame($gravatar, PeepSoMediaCache::avatarUrl(37));
    }

    // ── Bulk path ────────────────────────────────────────────────────

    public function testBulkRepairsEveryCachedEntry(): void
    {
        self::poison(37, self::FROZEN);
        self::poison(38, self::REAL);
        self::poison(39, '');

        self::assertSame(
            [37 => self::FIXED, 38 => self::REAL, 39 => ''],
            PeepSoMediaCache::avatarUrlBulk([37, 38, 39])
        );
    }

    public function testBulkAndSingleAgree(): void
    {
        self::poison(37, self::FROZEN);

        self::assertSame(
            PeepSoMediaCache::avatarUrl(37),
            PeepSoMediaCache::avatarUrlBulk([37])[37]
        );
    }

    // ── Cover photos ─────────────────────────────────────────────────

    public function testCoverPhotoIsRepaired(): void
    {
        $frozen = 'https://bluecollarcrypto.io/wp-content/peepso/users/37/cover.jpg';
        wp_cache_set('cover:37', $frozen, 'bcc_core:user_media', 3600);

        self::assertSame(
            'https://cms.bluecollarcrypto.io/wp-content/peepso/users/37/cover.jpg',
            PeepSoMediaCache::coverPhotoUrl(37)
        );
    }

    public function testCoverPhotoAbsenceStaysNull(): void
    {
        // Cached '' encodes "no cover" and must keep decoding to null.
        wp_cache_set('cover:37', '', 'bcc_core:user_media', 3600);

        self::assertNull(PeepSoMediaCache::coverPhotoUrl(37));
    }

    // ── No-op on a conventional install ──────────────────────────────

    public function testUnsplitInstallIsUntouched(): void
    {
        \BccTestSiteUrls::unsplit('https://bluecollarcrypto.io');
        self::poison(37, self::FROZEN);

        self::assertSame(self::FROZEN, PeepSoMediaCache::avatarUrl(37));
    }
}
