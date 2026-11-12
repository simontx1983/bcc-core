<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Repositories\PeepSoHashtagRepository;
use PHPUnit\Framework\TestCase;

/**
 * The cached public trending ranking (F-168) counts only posts anyone can
 * see, and a group's privacy decides whether its posts are in that set. So
 * every write to a group's `peepso_group_privacy` meta (added, updated or
 * deleted) must drop the ranking, beside the non-open group cache it
 * depends on.
 *
 * bcc-core.php registers the listener at plugin load (WordPress hooks), which
 * the unit suite cannot boot, so this pins the wiring in its source; the
 * bust itself is driven in PublicTrendingHashtagsTest.
 */
final class TrendingCacheGroupPrivacyWiringTest extends TestCase
{
    public function testAGroupPrivacyWriteDropsTheTrendingCache(): void
    {
        $src = file_get_contents(__DIR__ . '/../bcc-core.php');
        self::assertIsString($src);
        $flat = (string) preg_replace('/\s+/', ' ', $src);

        $listener = "if (is_string(\$metaKey) && \$metaKey === 'peepso_group_privacy') { "
            . '\BCC\Core\Repositories\PeepSoGroupRepository::bustNonOpenGroupIdsCache(); '
            . '\BCC\Core\Repositories\PeepSoHashtagRepository::bustPublicTrendingCache(); }';
        self::assertStringContainsString($listener, $flat, 'the privacy-meta listener drops the trending ranking too');

        $start = strpos($flat, '$bccBustNonOpenGroupCache = static function');
        self::assertNotFalse($start);
        self::assertSame($start + strpos(substr($flat, $start), $listener), strpos($flat, $listener), 'inside that listener');

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            self::assertStringContainsString("add_action('{$hook}', \$bccBustNonOpenGroupCache, 10, 3);", $flat);
        }

        self::assertTrue(method_exists(PeepSoHashtagRepository::class, 'bustPublicTrendingCache'));
    }
}
