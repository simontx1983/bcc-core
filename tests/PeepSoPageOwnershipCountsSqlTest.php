<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoPageRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!\defined('ARRAY_A')) {
    // WordPress defines ARRAY_A as the literal string 'ARRAY_A'.
    \define('ARRAY_A', 'ARRAY_A');
}

/**
 * Page-ownership counts only count pages others may see (F-427).
 *
 * The member card's `owned_pages_by_type`, the directory's `?type=` filter
 * and its chip counts each require listablePageSql(): a published
 * `peepso-page` that is not secret (the shared PeepSoPagePrivacy rule).
 * Rationale: F-427 (private tracker).
 *
 * This pins the condition and that every reader carries it. bcc-trust's
 * integration suite runs the statements against MySQL 8 and MariaDB.
 */
#[CoversMethod(PeepSoPageRepository::class, 'listablePageSql')]
#[CoversMethod(PeepSoPageRepository::class, 'getOwnedPageTypeCountsForUsers')]
#[CoversMethod(PeepSoPageRepository::class, 'getUserIdsOwningPagesOfType')]
#[CoversMethod(PeepSoPageRepository::class, 'getGlobalOwnedPageUserCountsByType')]
final class PeepSoPageOwnershipCountsSqlTest extends TestCase
{
    private const LISTABLE =
        "EXISTS (SELECT 1 FROM wp_posts bcc_listable_pg"
        . " WHERE bcc_listable_pg.ID = pm.pm_page_id"
        . " AND bcc_listable_pg.post_type = 'peepso-page'"
        . " AND bcc_listable_pg.post_status = 'publish')"
        . " AND NOT EXISTS (SELECT 1 FROM wp_postmeta bcc_page_priv"
        . " WHERE bcc_page_priv.post_id = pm.pm_page_id"
        . " AND bcc_page_priv.meta_key = 'peepso_page_privacy'"
        . " AND CAST(bcc_page_priv.meta_value AS UNSIGNED) = 2)";

    /** @var mixed */
    private $priorWpdb;

    /**
     * @var object{
     *   prefix: string, posts: string, postmeta: string, last_error: string,
     *   rows: list<array<string, string>>, queries: list<string>
     * }
     */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb = new class {
            public string $prefix     = 'wp_';
            public string $posts      = 'wp_posts';
            public string $postmeta   = 'wp_postmeta';
            public string $last_error = '';

            /** @var list<array<string, string>> rows get_results() answers (ARRAY_A, strings as wpdb returns) */
            public array $rows = [];

            /** @var list<string> every statement run, whitespace-collapsed */
            public array $queries = [];

            /** Renders %d / %s like wpdb and refuses a placeholder count mismatch. */
            public function prepare(string $sql, mixed ...$args): string
            {
                $next = 0;
                $out  = preg_replace_callback(
                    '/%[ds]/',
                    static function (array $m) use (&$next, $args): string {
                        $value = $args[$next++] ?? '<<UNBOUND>>';
                        return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
                    },
                    $sql
                );
                TestCase::assertSame(count($args), $next, 'every bound argument must have a placeholder');
                return (string) $out;
            }

            /** @return list<array<string, string>> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                TestCase::assertSame('ARRAY_A', $output);
                $this->queries[] = (string) preg_replace('/\s+/', ' ', $sql);
                return $this->rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
        parent::tearDown();
    }

    public function testAListablePageIsAPublishedPeepSoPageThatIsNotSecret(): void
    {
        self::assertSame(self::LISTABLE, PeepSoPageRepository::listablePageSql('pm.pm_page_id'));
    }

    public function testTheConditionIsSafeInsideAPreparedStatement(): void
    {
        self::assertStringNotContainsString('%', PeepSoPageRepository::listablePageSql('pm.pm_page_id'));
    }

    public function testOnlyAColumnReferenceIsAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PeepSoPageRepository::listablePageSql('pm.pm_page_id OR 1=1');
    }

    /** @return iterable<string, array{0: \Closure(): mixed}> */
    public static function readers(): iterable
    {
        yield 'member card counts per type' => [static fn () => PeepSoPageRepository::getOwnedPageTypeCountsForUsers([5, 9])];
        yield 'owners of a type (?type= filter)' => [static fn () => PeepSoPageRepository::getUserIdsOwningPagesOfType('validator')];
        yield 'global chip counts' => [static fn () => PeepSoPageRepository::getGlobalOwnedPageUserCountsByType()];
    }

    /** @param \Closure(): mixed $read */
    #[DataProvider('readers')]
    public function testEveryOwnershipReadCountsOnlyListablePages(\Closure $read): void
    {
        $read();

        self::assertCount(1, $this->wpdb->queries, 'one bounded read');
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("pm.pm_user_status = 'member_owner'", $sql);
        self::assertStringContainsString('AND ' . self::LISTABLE . ' GROUP BY pm.pm_user_id', $sql);
    }

    public function testTheTypeFilterStillBindsItsCategorySlugs(): void
    {
        PeepSoPageRepository::getUserIdsOwningPagesOfType('validator');

        self::assertStringContainsString("cat.post_name IN ('vaildators','validators')", $this->wpdb->queries[0]);
    }

    public function testCountsStillMapCategorySlugsToTypes(): void
    {
        $this->wpdb->rows = [
            ['user_id' => '5', 'type_slug' => 'vaildators', 'c' => '1'],
            ['user_id' => '5', 'type_slug' => 'builders', 'c' => '2'],
            ['user_id' => '9', 'type_slug' => 'unknown-slug', 'c' => '4'],
        ];

        self::assertSame(
            [
                5 => ['validator' => 1, 'project' => 2, 'nft' => 0, 'dao' => 0],
                9 => ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0],
            ],
            PeepSoPageRepository::getOwnedPageTypeCountsForUsers([5, 9])
        );
    }
}
