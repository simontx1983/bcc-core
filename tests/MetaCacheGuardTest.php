<?php

declare(strict_types=1);

namespace BCC\Core\Meta\Tests;

use BCC\Core\Meta\MetaCacheGuard;
use BCC\Core\Meta\MetaReadHealth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-459 — a failed meta read must not be CACHED.
 *
 * WordPress's `update_meta_cache()` cannot tell a failed SELECT from "these
 * objects have no meta": after a driver error `flush()` has already reset
 * `last_result`, so `$meta_list` is `array()` either way. It then stores
 * `array()` for every requested id. On a persistent object cache
 * `post_meta` has no expiry, so one fault makes every absent-means-allowed
 * gate fail open until that object's meta is written.
 *
 * ⚠⚠ THE FIRST TEST IS THE MOST IMPORTANT ONE, and it guards against the
 * fix rather than the bug. `update_meta_cache()` coerces any short-circuit
 * to a bool, and `get_metadata_raw()` then reads
 * `$meta_cache[$object_id] ?? null`. So a filter that caches the rows and
 * returns the array — or `true` — makes every object's first meta read in a
 * request come back EMPTY on a healthy database, which would fail every
 * gate open all of the time. Returning NULL after populating is the only
 * shape that works, and
 * {@see testOnSuccessItReturnsNullSoCoreCanAnswerFromTheCache} is what
 * stops someone "simplifying" it.
 */
#[CoversClass(MetaCacheGuard::class)]
#[CoversClass(MetaReadHealth::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MetaCacheGuardTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb = null;

    private MetaWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠⚠ REQUIRED HERE, NOT AT FILE SCOPE. PHPUnit loads every test file
        // in the PARENT process to collect tests, even for a class marked
        // RunTestsInSeparateProcesses — so a file-scope require defines these
        // stubs globally and they leak into every other test in the run.
        //
        // That is not theoretical: with the require at file scope, this
        // file's recording `Logger` SHADOWED the real one, and
        // LoggerWalletRedactionTest — whose whole job is proving wallet
        // addresses are redacted from logs — reflected on my fake and
        // errored with "redactSensitive() does not exist". Eleven tests
        // failed, and had the shadow been silent instead of fatal, a
        // security test would have been quietly testing nothing.
        require_once __DIR__ . '/Stubs/meta-cache-guard-stubs.php';

        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new MetaWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        MetaStore::reset();
        MetaReadHealth::reset();
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
        MetaReadHealth::reset();
        parent::tearDown();
    }

    /** @param array<string, string> $row */
    private static function row(array $row): object
    {
        return (object) $row;
    }

    /** @return mixed */
    private function prime(string $type, array $ids, $check = null)
    {
        $method = $type === 'user' ? 'primeUserMeta' : 'primePostMeta';
        return MetaCacheGuard::$method($check, $ids);
    }

    /** @return array<int|string, mixed> */
    private static function cacheFor(string $group): array
    {
        return MetaStore::$cache[$group] ?? [];
    }

    // ── the regression the shape exists to avoid ───────────────────────

    /**
     * ⭐ On success the filter must return NULL. Anything non-null is
     * coerced to bool by `update_meta_cache()`, and `get_metadata_raw()`
     * then resolves to null and answers '' — on a HEALTHY database.
     */
    public function testOnSuccessItReturnsNullSoCoreCanAnswerFromTheCache(): void
    {
        $this->wpdb->rows = [
            self::row(['post_id' => '7', 'meta_key' => 'colour', 'meta_value' => 'blue']),
        ];

        self::assertNull(
            $this->prime('post', [7]),
            'a non-null return makes every first meta read answer empty on a healthy DB'
        );
        // And it really did populate, so core finds it without a second query.
        self::assertSame(['colour' => ['blue']], self::cacheFor('post_meta')[7]);
    }

    // ── equivalence with core's stored shape ──────────────────────────

    /**
     * The cache contents must be what core's `update_meta_cache()` would
     * have stored for the same rows.
     *
     * ⚠ This is an EXPLICIT EXPECTATION, not a live comparison against
     * core's function: bcc-core's unit bootstrap loads no WordPress, so
     * `update_meta_cache()` is not callable here, and bcc-trust's
     * integration harness shims `get_post_meta` rather than loading
     * `wp-includes/meta.php`. The expectation below mirrors
     * meta.php (WP 7.0.2) lines 1227-1250 — the two `is_array` coercions,
     * the `$cache[$mpid][$mkey][] = $mval` append, and the
     * `$cache[$id] = array()` fill for ids with no rows — so a reviewer can
     * check it against core's source once, after which it pins drift here.
     */
    public function testItStoresExactlyWhatCoreWouldStore(): void
    {
        // Deliberately covers: several ids; an id with NO rows; several
        // values under one key; an empty-string value; and meta_id order.
        $this->wpdb->rows = [
            self::row(['post_id' => '10', 'meta_key' => 'tag',   'meta_value' => 'first']),
            self::row(['post_id' => '10', 'meta_key' => 'tag',   'meta_value' => 'second']),
            self::row(['post_id' => '10', 'meta_key' => 'empty', 'meta_value' => '']),
            self::row(['post_id' => '11', 'meta_key' => 'only',  'meta_value' => 'one']),
        ];

        self::assertNull($this->prime('post', [10, 11, 12]));

        self::assertSame(
            [
                // Multiple values under one key keep meta_id order; the
                // empty string is STORED, not dropped.
                10 => ['tag' => ['first', 'second'], 'empty' => ['']],
                11 => ['only' => ['one']],
                // ⭐ An id with no rows gets array(), which is what stops a
                // second read re-querying it. Part of the equivalence, not
                // an optimisation.
                12 => [],
            ],
            self::cacheFor('post_meta')
        );
    }

    public function testTheQueryMatchesTheOneCoreWouldRun(): void
    {
        $this->wpdb->rows = [];
        $this->prime('post', [5, 6]);

        self::assertCount(1, MetaStore::$queries);
        self::assertSame(
            'SELECT post_id, meta_key, meta_value FROM wp_postmeta'
            . ' WHERE post_id IN (5,6) ORDER BY meta_id ASC',
            MetaStore::$queries[0]
        );
    }

    /** User meta uses its own table, column and id column. */
    public function testUserMetaUsesUmetaIdOrdering(): void
    {
        $this->wpdb->rows = [];
        $this->prime('user', [3]);

        self::assertSame(
            'SELECT user_id, meta_key, meta_value FROM wp_usermeta'
            . ' WHERE user_id IN (3) ORDER BY umeta_id ASC',
            MetaStore::$queries[0]
        );
    }

    // ── failure: cache nothing ────────────────────────────────────────

    /**
     * ⭐ A driver error looks exactly like "no rows" through
     * `get_results()`, because `flush()` has already reset `last_result`.
     * Only `last_error` distinguishes them — so this is the case the whole
     * fix turns on.
     */
    public function testADriverErrorCachesNothingAndShortCircuits(): void
    {
        $this->wpdb->mode = 'driver_error';

        self::assertFalse($this->prime('post', [20, 21]), 'it must short-circuit, not let core cache []');
        self::assertSame([], self::cacheFor('post_meta'), 'it cached a failed read');
        self::assertSame([], MetaStore::$added, 'it called wp_cache_add_multiple on a failed read');
    }

    /**
     * ⭐⭐ THE PRE-FLUSH BAIL. `wpdb::query()` returns false on a failed
     * reconnect, or when the `query` filter empties the SQL — both BEFORE
     * `flush()`. So `last_result` still holds the PREVIOUS query's rows and
     * `last_error` is empty, and `get_results()` hands those rows back with
     * nothing to indicate it.
     *
     * Caching them would store one object's meta against another's id,
     * persistently, for every reader. That is fail-WRONG, not fail-open,
     * and it is why this uses ProbesReads rather than `get_results()`.
     */
    public function testThePreFlushBailCachesNothingEvenWithNoLastError(): void
    {
        $this->wpdb->mode      = 'pre_flush_bail';
        $this->wpdb->staleRows = [
            // Another query's rows, for a DIFFERENT post.
            self::row(['post_id' => '999', 'meta_key' => 'secret', 'meta_value' => 'leaked']),
        ];

        self::assertFalse($this->prime('post', [20, 21]));
        self::assertSame('', $this->wpdb->last_error, 'the bail leaves no error — that is the trap');
        self::assertSame([], self::cacheFor('post_meta'), 'another query\'s rows were cached');
    }

    public function testAfterAFailureARetryReadsCorrectly(): void
    {
        $this->wpdb->mode = 'driver_error';
        self::assertFalse($this->prime('post', [30]));
        self::assertSame([], self::cacheFor('post_meta'));

        // The fault clears; nothing poisoned is in the way.
        $this->wpdb->mode = 'ok';
        $this->wpdb->rows = [
            self::row(['post_id' => '30', 'meta_key' => 'colour', 'meta_value' => 'green']),
        ];

        self::assertNull($this->prime('post', [30]));
        self::assertSame(['colour' => ['green']], self::cacheFor('post_meta')[30]);
    }

    // ── the health signal ─────────────────────────────────────────────

    #[DataProvider('failureModes')]
    public function testAFailureIsRecordedAgainstTheIdsItAffected(string $mode): void
    {
        $this->wpdb->mode = $mode;

        $this->prime('post', [40, 41]);

        self::assertTrue(MetaReadHealth::failed('post'));
        self::assertTrue(MetaReadHealth::failedFor('post', 40));
        self::assertTrue(MetaReadHealth::failedFor('post', 41));
        self::assertSame([40, 41], MetaReadHealth::failedIds('post'));

        // ⚠ Per-id and per-type: an unrelated object, and user meta, are
        // NOT tainted. A gate about one post must not refuse because
        // another batch failed.
        self::assertFalse(MetaReadHealth::failedFor('post', 42));
        self::assertFalse(MetaReadHealth::failed('user'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function failureModes(): iterable
    {
        yield 'a driver error'    => ['driver_error'];
        yield 'a pre-flush bail'  => ['pre_flush_bail'];
    }

    /** Anti-vacuity: a healthy read records nothing. */
    public function testASuccessfulReadRecordsNoFailure(): void
    {
        $this->wpdb->rows = [
            self::row(['post_id' => '50', 'meta_key' => 'a', 'meta_value' => 'b']),
        ];

        $this->prime('post', [50]);

        self::assertFalse(MetaReadHealth::failed('post'));
        self::assertSame([], MetaReadHealth::failedIds('post'));
        self::assertSame([], MetaStore::$metrics, 'a healthy read counted a degradation');
        self::assertSame([], MetaStore::$logs, 'a healthy read logged an error');
    }

    /**
     * Ops has to be able to see how often this fires BEFORE F-460 / F-461
     * start refusing requests on it, so the counter is part of the fix
     * rather than decoration — and the event must name the right meta type,
     * or post and user faults become indistinguishable on the dashboard.
     *
     * @param 'post'|'user' $type
     */
    #[DataProvider('metaTypesAndEvents')]
    public function testAFailureIsCountedAgainstTheRightEvent(string $type, int $id, string $event): void
    {
        $this->wpdb->mode = 'driver_error';

        $this->prime($type, [$id]);

        self::assertSame(
            [['subsystem' => 'meta_cache', 'event' => $event]],
            MetaStore::$metrics
        );
        self::assertCount(1, MetaStore::$logs, 'the failure was not logged');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: string}> */
    public static function metaTypesAndEvents(): iterable
    {
        yield 'post meta' => ['post', 90, 'post_meta_read'];
        yield 'user meta' => ['user', 91, 'user_meta_read'];
    }

    // ── partitioning ──────────────────────────────────────────────────

    /**
     * Already-cached ids are not re-queried, and — the part that is easy to
     * get wrong — a cached EMPTY array is a HIT. Treating it as a miss would
     * re-query every object that genuinely has no meta, on every request.
     */
    public function testAlreadyCachedIdsAreNotRequeriedIncludingEmptyOnes(): void
    {
        MetaStore::$cache['post_meta'] = [
            60 => ['colour' => ['blue']],
            61 => [],
        ];
        $this->wpdb->rows = [
            self::row(['post_id' => '62', 'meta_key' => 'new', 'meta_value' => 'row']),
        ];

        self::assertNull($this->prime('post', [60, 61, 62]));

        self::assertCount(1, MetaStore::$queries);
        self::assertStringContainsString('IN (62)', MetaStore::$queries[0], 'it re-queried cached ids');
        // The cached entries are untouched.
        self::assertSame(['colour' => ['blue']], self::cacheFor('post_meta')[60]);
        self::assertSame([], self::cacheFor('post_meta')[61]);
    }

    public function testNothingIsQueriedWhenEveryIdIsCached(): void
    {
        MetaStore::$cache['post_meta'] = [70 => [], 71 => ['k' => ['v']]];

        self::assertNull($this->prime('post', [70, 71]));
        self::assertSame([], MetaStore::$queries, 'it queried for ids that were already cached');
    }

    // ── pass-through and guards ───────────────────────────────────────

    /**
     * Another plugin short-circuited first. Its answer must pass through
     * unchanged — overriding it would make this filter the arbiter of
     * someone else's decision.
     *
     * @param mixed $check
     */
    #[DataProvider('foreignShortCircuits')]
    public function testAnExistingShortCircuitPassesThroughUnchanged($check): void
    {
        $this->wpdb->rows = [self::row(['post_id' => '80', 'meta_key' => 'a', 'meta_value' => 'b'])];

        self::assertSame($check, $this->prime('post', [80], $check));
        self::assertSame([], MetaStore::$queries, 'it queried despite another filter having decided');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function foreignShortCircuits(): iterable
    {
        yield 'false' => [false];
        yield 'true'  => [true];
        yield 'array' => [[80 => []]];
    }

    #[DataProvider('emptyIdSets')]
    public function testAnEmptyIdSetIsLeftToCore(mixed $ids): void
    {
        self::assertNull($this->prime('post', $ids));
        self::assertSame([], MetaStore::$queries);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function emptyIdSets(): iterable
    {
        yield 'empty array' => [[]];
    }
}
