<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupPrivacy;
use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoGroupPrivacy — the shared SECRET-group rule (F-429).
 *
 * ⚠ READ THIS BEFORE TRUSTING THIS FILE. These are SOURCE-SHAPE tests. They
 * can see what SQL is built; they cannot see what an engine does with it,
 * because bcc-core has no integration suite and a fake `$wpdb` cannot
 * execute SQL. So the central claim — that the old `LEFT JOIN … (IS NULL OR
 * <> 2)` form LISTS a group carrying both a '0' row and a '2' row, and
 * returns a two-row group twice, while `NOT EXISTS` does neither — is proven
 * by running both predicates against a real SQL engine, recorded in the
 * F-429 PR. What is pinned here is that the built SQL keeps the shape that
 * behaviour depends on, and that the old shape cannot come back unnoticed.
 *
 * The page twin's SQL is proven against MySQL 8 and MariaDB by bcc-trust's
 * integration suite (SecretPageVisibilityIntegrationTest). The group rule
 * has no such home yet; see the PR for that follow-up.
 */
#[CoversClass(PeepSoGroupPrivacy::class)]
final class PeepSoGroupPrivacyTest extends TestCase
{
    /** @var object{posts: string, postmeta: string, prepared: list<array{0: string, 1: list<mixed>}>, col: list<string>} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];
            /** @var list<string> */
            public array $col = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                return $this->col;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── the rule itself ────────────────────────────────────────────────

    public function testItAsksWhetherAnySecretRowExists(): void
    {
        $sql = PeepSoGroupPrivacy::notSecretSql('p.ID');

        self::assertStringStartsWith('NOT EXISTS (', $sql);
        self::assertStringContainsString('wp_postmeta', $sql);
        self::assertStringContainsString("bcc_group_priv.post_id = p.ID", $sql);
    }

    /**
     * Anti-vacuity. Without these, a predicate that named the wrong meta key
     * or compared against the wrong value would satisfy every other
     * assertion in this file while hiding nothing.
     */
    public function testItNamesTheGroupPrivacyKeyAndTheSecretValue(): void
    {
        $sql = PeepSoGroupPrivacy::notSecretSql('p.ID');

        self::assertSame('peepso_group_privacy', PeepSoGroupPrivacy::META_KEY);
        self::assertSame(2, PeepSoGroupPrivacy::SECRET);
        self::assertStringContainsString("meta_key = 'peepso_group_privacy'", $sql);
        self::assertStringContainsString('= 2', $sql);
        // Not the PAGE key — a copy-paste twin is the obvious way to get this wrong.
        self::assertStringNotContainsString('peepso_page_privacy', $sql);
    }

    /**
     * ⭐ The regression guard, and the reason this file exists. Each of these
     * fragments is a load-bearing part of the form F-429 replaced; any one of
     * them reappearing means the leak is back.
     */
    #[DataProvider('oldFormMarkers')]
    public function testTheSupersededFormCannotComeBack(string $marker): void
    {
        self::assertStringNotContainsString(
            $marker,
            PeepSoGroupPrivacy::notSecretSql('p.ID'),
            'the pre-F-429 LEFT JOIN form admitted a group with two privacy rows'
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function oldFormMarkers(): iterable
    {
        yield 'no outer join'            => ['LEFT JOIN'];
        yield 'no null-means-visible'    => ['IS NULL'];
        yield 'no not-equal comparison'  => ['<> 2'];
        yield 'no not-equal, spaced'     => ['!= 2'];
    }

    /**
     * The fragment is concatenated into a string that is later handed to
     * `$wpdb->prepare()`, so a stray `%` would be read as a placeholder and
     * shift every real argument along by one.
     */
    public function testItCarriesNoPlaceholderForPrepare(): void
    {
        self::assertStringNotContainsString('%', PeepSoGroupPrivacy::notSecretSql('p.ID'));
    }

    #[DataProvider('columnReferences')]
    public function testItAcceptsAColumnReference(string $column): void
    {
        self::assertStringContainsString(
            "bcc_group_priv.post_id = {$column}",
            PeepSoGroupPrivacy::notSecretSql($column)
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function columnReferences(): iterable
    {
        yield 'qualified'   => ['p.ID'];
        yield 'bare'        => ['ID'];
        yield 'underscored' => ['grp.post_id'];
    }

    #[DataProvider('refusedArguments')]
    public function testItRefusesAnythingThatIsNotAColumnReference(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PeepSoGroupPrivacy::notSecretSql($argument);
    }

    // ── the deny predicate (nonOpenSql) ────────────────────────────────

    /**
     * The exact text of both predicates. notSecretSql() now goes through the
     * same builder as nonOpenSql() and must emit what it always did, and
     * nonOpenSql() must read the value the same way: `CAST … AS UNSIGNED`,
     * never a string compare. The engines are in PeepSoGroupPrivacyReadTest.
     */
    public function testBothPredicatesReadTheValueWithTheSameCast(): void
    {
        $row = 'SELECT 1 FROM wp_postmeta bcc_group_priv WHERE bcc_group_priv.post_id = p.ID'
            . " AND bcc_group_priv.meta_key = 'peepso_group_privacy'"
            . ' AND CAST(bcc_group_priv.meta_value AS UNSIGNED) ';

        self::assertSame('NOT EXISTS (' . $row . '= 2)', PeepSoGroupPrivacy::notSecretSql('p.ID'));
        self::assertSame('EXISTS (' . $row . 'IN (1, 2))', PeepSoGroupPrivacy::nonOpenSql('p.ID'));
    }

    public function testTheDenyPredicateCarriesNoPlaceholderForPrepare(): void
    {
        self::assertStringNotContainsString('%', PeepSoGroupPrivacy::nonOpenSql('p.ID'));
    }

    // ── the public-listing predicate (openSql, F-518) ──────────────────

    /**
     * Explicitly open: a row reads 0 AND the deny predicate is false. Same
     * value reading (CAST) as the other two, and built on nonOpenSql() so a
     * two-row group the deny list catches can never also be listed.
     */
    public function testTheOpenPredicateIsAnOpenRowAndNotNonOpen(): void
    {
        $row = 'SELECT 1 FROM wp_postmeta bcc_group_priv WHERE bcc_group_priv.post_id = p.ID'
            . " AND bcc_group_priv.meta_key = 'peepso_group_privacy'"
            . ' AND CAST(bcc_group_priv.meta_value AS UNSIGNED) ';

        self::assertSame(
            'EXISTS (' . $row . '= 0) AND NOT ' . PeepSoGroupPrivacy::nonOpenSql('p.ID'),
            PeepSoGroupPrivacy::openSql('p.ID')
        );
    }

    public function testTheOpenPredicateCarriesNoPlaceholderForPrepare(): void
    {
        self::assertStringNotContainsString('%', PeepSoGroupPrivacy::openSql('p.ID'));
    }

    #[DataProvider('refusedArguments')]
    public function testTheOpenPredicateRefusesAnythingThatIsNotAColumnReference(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('openSql() takes a column reference');
        PeepSoGroupPrivacy::openSql($argument);
    }

    #[DataProvider('refusedArguments')]
    public function testTheDenyPredicateRefusesAnythingThatIsNotAColumnReference(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonOpenSql() takes a column reference');
        PeepSoGroupPrivacy::nonOpenSql($argument);
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedArguments(): iterable
    {
        yield 'empty'              => [''];
        yield 'a literal'          => ['1'];
        yield 'a placeholder'      => ['%d'];
        yield 'quoted'             => ["'p.ID'"];
        yield 'a subquery'         => ['(SELECT 1)'];
        yield 'injection attempt'  => ['p.ID) OR 1=1 -- '];
        yield 'three parts'        => ['db.wp_posts.ID'];
        yield 'trailing space'     => ['p.ID '];
    }

    // ── the consumer ───────────────────────────────────────────────────

    public function testListBrowsableGroupIdsUsesTheSharedRule(): void
    {
        $this->wpdb->col = ['7', '4', '1'];

        self::assertSame([7, 4, 1], PeepSoGroupRepository::listBrowsableGroupIds(10));

        self::assertCount(1, $this->wpdb->prepared);
        [$sql, $args] = $this->wpdb->prepared[0];

        self::assertStringContainsString('NOT EXISTS (', $sql);
        self::assertStringContainsString("meta_key = 'peepso_group_privacy'", $sql);
    }

    /**
     * ⭐ The join is gone, not merely unused. While the read joined postmeta
     * for the privacy check, a group with two privacy rows came back once per
     * row — so the duplicate-row symptom was a property of the JOIN, not of
     * the comparison. Leaving the join in place and only changing the
     * comparison would have fixed the leak and kept the duplication.
     */
    public function testTheJoinOnPostmetaIsGone(): void
    {
        $this->wpdb->col = [];
        PeepSoGroupRepository::listBrowsableGroupIds(10);

        [$sql, $args] = $this->wpdb->prepared[0];

        self::assertStringNotContainsString('LEFT JOIN', $sql);
        self::assertStringNotContainsString('IS NULL', $sql);
        // The meta key is now baked into the NOT EXISTS fragment, so it is no
        // longer a prepared argument. If it reappears here, the join is back.
        self::assertNotContains('peepso_group_privacy', $args);
        self::assertSame(['peepso-group', 'publish', 10], $args);
    }

    /** A read that returns nothing is an empty list, not a list of zeroes. */
    public function testAnEmptyReadIsAnEmptyList(): void
    {
        $this->wpdb->col = [];
        self::assertSame([], PeepSoGroupRepository::listBrowsableGroupIds(10));
    }
}
