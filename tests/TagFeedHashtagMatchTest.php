<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\TagFeedHashtagMatch {

    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Repositories\PeepSoHashtagRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * F-268: the tag feed must select exactly the posts trending counts.
     *
     * Trending recounts each tag with PeepSo's own whole-word predicate
     * (`LIKE '% #tag %'` on space-normalised content). The tag feed used a
     * bare `LIKE '%#tag%'`, so tapping "#eth · 3" listed `#ethereum`,
     * `#eth2` and `#ethics` posts too, and `tag=039` matched every post
     * with an apostrophe (PeepSo stores it as `&#039;`).
     *
     * Both readers now render ONE predicate, PeepSoHashtagRepository::
     * matchSql(). These pin the rendered statement (the double binds the
     * value into the SQL); the whole-word semantics of the predicate itself
     * are PeepSo's and are what PublicTrendingHashtagsTest already pins.
     */
    #[CoversClass(PeepSoActivityRepository::class)]
    #[CoversClass(PeepSoHashtagRepository::class)]
    final class TagFeedHashtagMatchTest extends TestCase
    {
        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
            \bcc_test_seed_nonopen_groups([]);
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        }

        protected function tearDown(): void
        {
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private function tagFeedSql(string $tag): string
        {
            $wpdb            = new RenderingWpdb();
            $GLOBALS['wpdb'] = $wpdb;

            PeepSoActivityRepository::getActivities(limit: 5, hashtag: $tag);

            return trim($wpdb->lastSql);
        }

        /** The predicate exactly as trending renders it, bound to $tag. */
        private static function sharedPredicate(string $tag): string
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();

            return (new RenderingWpdb())->prepare(
                PeepSoHashtagRepository::matchSql('p.post_content'),
                PeepSoHashtagRepository::matchParam($tag)
            );
        }

        public function testTheTagFeedUsesTheTrendingPredicate(): void
        {
            $sql = $this->tagFeedSql('eth');

            self::assertStringContainsString(self::sharedPredicate('eth'), $sql);
            self::assertStringContainsString("LIKE '% #eth %'", $sql, 'whole word: a space on both sides');
        }

        public function testThePrefixMatchIsGone(): void
        {
            $sql = $this->tagFeedSql('eth');

            self::assertStringNotContainsString("LIKE '%#eth%'", $sql, '#eth must not match #ethereum');
            self::assertStringNotContainsString('p.post_content LIKE', $sql, 'no bare substring match on the raw column');
        }

        public function testTheContentIsNormalisedLikePeepSo(): void
        {
            $predicate = PeepSoHashtagRepository::matchSql('p.post_content');

            // Wrapped in spaces, so a tag at the very start or end matches;
            // tab, CR, LF and NBSP read as spaces.
            self::assertStringStartsWith("CONCAT(' ', ", $predicate);
            foreach (['CHAR(9)', 'CHAR(13)', 'CHAR(10)', 'CHAR(194, 160 USING utf8mb4)'] as $ws) {
                self::assertStringContainsString($ws, $predicate);
            }
            self::assertSame(1, substr_count($predicate, '%s'), 'exactly one placeholder');
        }

        public function testLikeWildcardsInsideATagStayLiteral(): void
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();

            self::assertSame('% #c\_plus %', PeepSoHashtagRepository::matchParam('c_plus'));
            self::assertSame('% #100\% %', PeepSoHashtagRepository::matchParam('100%'));
        }

        public function testNoHashtagAddsNoPredicate(): void
        {
            self::assertStringNotContainsString(' #', $this->tagFeedSql(''));
        }
    }
}
