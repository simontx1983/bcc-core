<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoAddPostGuard;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-361 — a BCC post-create must not let the REQUEST route the post.
 *
 * ⚠⚠ THE PROPERTY UNDER TEST. `PeepSoActivity::add_post()` fires
 * `peepso_activity_after_add_post`, and every listener on it but one reads
 * the current request to decide what to do, with no authorization of its
 * own. So request input BCC never set must not be able to reach a listener
 * and route the post — the guard pins the request for the duration of the
 * write so that only BCC's own arguments reach them. The mechanism and the
 * reachability analysis are with the security review (private tracker).
 *
 * ⚠ WHAT THESE TESTS CAN AND CANNOT SHOW. The listeners here are faithful
 * re-implementations of PeepSo's, cited line by line in
 * `tests/Stubs/add-post-guard-stubs.php`, including the `$_GET` → `$_POST` →
 * raw-body precedence that is the heart of the bug. The guard under test is
 * the real one. So these prove the guard pins the request, drops the right
 * callbacks, keeps the one content-driven listener, and restores both — but
 * not that PeepSo's own listeners behave as modelled. That rests on the
 * file:line citations, read against PeepSo 9.0.5.5.
 */
#[CoversClass(PeepSoAddPostGuard::class)]
#[CoversClass(PeepSoStatusWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoAddPostGuardTest extends TestCase
{
    private const AUTHOR      = 51;
    private const SECRET_HALL = 9001;
    /** The wp_post id the stubbed add_post() returns. */
    private const POST_ID     = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/add-post-guard-stubs.php';
        require_once __DIR__ . '/Stubs/usermeta-wpdb.php';

        // ⛔⛔ F-498 — the stamps are now read through
        // `PostMetaRepository::valuesOrUnknown()`, which goes to `$wpdb`, so
        // seeding the `get_post_meta()` stub no longer reaches the guard.
        //
        // ⚠ This REPLACES the stubs' own `$wpdb`, so it has to keep that
        // one's `get_var()` answer: the writer path resolves the activity id
        // through it, and dropping it made two legitimate-write tests fail
        // for a reason that had nothing to do with stamps.
        //
        // ⚠ Not the shared Stubs UserMetaWpdb that #164 drives this same
        // read with: that class is final and models no `get_var()`. The two
        // failure modes below are written against ProbesReads' real
        // contract — trusted iff `query() !== false` AND `last_error` is
        // empty afterwards.
        $GLOBALS['__apg_modes'] = [];
        $GLOBALS['wpdb']        = new class {
            public string $prefix     = 'wp_';
            public string $postmeta   = 'wp_postmeta';
            public string $last_error = '';
            /** @var list<object> */
            public array $last_result = [];
            public int $metaQueries   = 0;

            /**
             * ⚠ THE PLACEHOLDERS MUST BE SUBSTITUTED, not passed through.
             * `valuesOrUnknown()` builds its SQL with `%d`/`%s` and the keys
             * arrive as ARGS, so a passthrough `prepare()` leaves the query
             * with no key names in it — `query()` below then matches nothing,
             * answers a TRUSTED EMPTY result, and every stamped-post test
             * reports clean. Same substitution as the shared Stubs double.
             *
             * @param mixed ...$args
             */
            public function prepare(string $sql, ...$args): string
            {
                $next = 0;

                return (string) preg_replace_callback(
                    '/%[ds]/',
                    static function (array $m) use (&$next, $args): string {
                        $value = $args[$next++] ?? '';

                        return $m[0] === '%d'
                            ? (string) (int) $value
                            : "'" . addslashes((string) $value) . "'";
                    },
                    $sql
                );
            }

            /** @param string $sql */
            public function get_var($sql): string
            {
                unset($sql);

                return '777';
            }

            /**
             * @param  string $sql
             * @return int|false
             */
            public function query($sql)
            {
                $this->last_error  = '';
                $this->last_result = [];

                if (stripos($sql, 'wp_postmeta') === false) {
                    return 0;
                }

                $this->metaQueries++;
                $mode = array_shift($GLOBALS['__apg_modes']) ?: 'ok';

                // A DRIVER FAILURE sets both signals ProbesReads reads.
                if ($mode === 'error') {
                    $this->last_error = 'meta read failed';

                    return false;
                }

                // THE PRE-FLUSH BAIL is the subtle one: a row count and NO
                // `last_error`, so BOTH of ProbesReads' checks pass, while
                // `last_result` still holds the PREVIOUS query's rows. Those
                // rows name an id this query never asked about, which is
                // what makes them recognisably not ours.
                if ($mode === 'preflush') {
                    $this->last_result = [
                        (object) ['post_id' => '999999', 'meta_key' => 'peepso_group_id', 'meta_value' => '1'],
                    ];

                    return 1;
                }

                $rows = [];
                foreach ((array) ($GLOBALS['__bcc_apg']['meta'] ?? []) as $postId => $byKey) {
                    foreach ((array) $byKey as $key => $value) {
                        if (!is_array($value) && strpos($sql, (string) $key) !== false) {
                            $rows[] = (object) [
                                'post_id'    => (string) $postId,
                                'meta_key'   => (string) $key,
                                'meta_value' => (string) $value,
                            ];
                        }
                    }
                }
                $this->last_result = $rows;

                return count($rows);
            }
        };

        $GLOBALS['__bcc_apg'] = [
            'meta' => [], 'ran' => [], 'fetched' => [], 'anon' => [],
            'albums' => [], 'deleted' => [], 'add_post' => [], 'notified' => [],
            'raw' => [], 'logged' => [], 'actions' => [],
        ];
        $GLOBALS['wp_filter'] = [];
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];

        // The real registration set, at PeepSo's real priorities.
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoActivity(), 'handle_embed_data'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoTags(), 'after_save_post'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoGroupsPlugin(), 'after_add_post'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoPagesPlugin(), 'after_add_post'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoPolls(), 'after_add_post'], 10, 1);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoPostBackgrounds(), 'after_add_post'], 10, 1);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoGiphy(), 'after_add_post'], 20, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoSharePhotos(), 'after_add_post'], 20, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoSharePhotos(), 'after_add_post_album'], 10, 2);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_apg'], $GLOBALS['wp_filter'], $GLOBALS['__apg_modes'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * Seed a stamp into BOTH stores — the one the guarded READ queries and
     * the one the write stubs manipulate — so a test cannot pass because it
     * seeded the store the code under test no longer looks at.
     *
     * @param int|string $value
     */
    private function seedStamp(int $postId, string $key, $value): void
    {
        $GLOBALS['__bcc_apg']['meta'][$postId][$key] = $value;
    }

    /** Script the meta queries in turn: 'ok', 'error' or 'preflush'. */
    private function metaReadModes(string ...$modes): void
    {
        $GLOBALS['__apg_modes'] = array_values($modes);
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        $meta = $GLOBALS['__bcc_apg']['meta'][self::POST_ID] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }

    /** @return list<string> */
    private function ran(): array
    {
        $ran = $GLOBALS['__bcc_apg']['ran'] ?? [];
        self::assertIsArray($ran);

        return array_values($ran);
    }

    /** A shape-only snapshot of the hook registry: priority => list of ids. */
    /** @return array<int, list<string>> */
    private function registry(): array
    {
        $out  = [];
        $hook = $GLOBALS['wp_filter']['peepso_activity_after_add_post'] ?? null;
        self::assertInstanceOf(\FakeWpHook::class, $hook);
        foreach ($hook->callbacks as $priority => $callbacks) {
            $ids = array_keys($callbacks);
            sort($ids);
            $out[(int) $priority] = $ids;
        }
        ksort($out);

        return $out;
    }

    // ── request input must not route the post ────────────────────────────────────────────────────────

    /**
     * ⭐ THE FIX. A query string naming a group BCC was never told about does
     * not reach the group listener, so nothing is stamped and nobody is
     * notified.
     */
    public function testAQueryStringCannotRouteAProfilePostIntoAGroup(): void
    {
        $_GET['group_id']  = (string) self::SECRET_HALL;
        $_GET['module_id'] = '8';

        $result = PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame(true, $result['ok'] ?? null, 'the legitimate profile post must still succeed');
        self::assertArrayNotHasKey('peepso_group_id', $this->meta(), 'the post was stamped as a group post');
        self::assertSame([], $GLOBALS['__bcc_apg']['notified'], 'a group was notified');
        self::assertNotContains(
            'PeepSoGroupsPlugin::after_add_post',
            $this->ran(),
            'the group listener ran at all — the detach did not take'
        );
    }

    /** The same for pages, including "post as page" authorship. */
    public function testAQueryStringCannotRouteAProfilePostOntoAPage(): void
    {
        $_GET['page_id']      = '77';
        $_GET['module_id']    = '9';
        $_GET['post_as_page'] = '1';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertArrayNotHasKey('peepso_page_id', $this->meta());
        self::assertArrayNotHasKey('peepso_post_as_page', $this->meta());
    }

    /**
     * The raw request body cannot route the post either.
     *
     * ⚠ MEASURED: for a DROPPED listener this is carried by the detach, not
     * by the pin — a mutation that replaced the neutral pins with the writer's
     * intent alone left this test green, because the group listener was not
     * there to read anything. {@see testTheRawBodyCannotReachEvenAKeptListener()}
     * is where the pin's raw-body protection is actually observable. Both are
     * kept: this one pins the end-to-end behaviour, that one pins the reason.
     */
    public function testTheRawRequestBodyCannotRouteThePostEither(): void
    {
        $GLOBALS['__bcc_apg']['raw'] = ['group_id' => (string) self::SECRET_HALL, 'module_id' => '8'];

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertArrayNotHasKey('peepso_group_id', $this->meta());
    }

    /**
     * ⚠⚠ THE RAW-BODY LEG AGAINST A LISTENER WE KEEP — the only place the PIN
     * itself is observable, and the reason it pins rather than unsets.
     *
     * `post()` returns its `$default` when the key is not set, and `int()`'s
     * default IS `raw_input()`. So `unset($_POST['anon_id'])` is PRECISELY
     * what opens `parse_str(file_get_contents('php://input'))`; pinning it to
     * 0 keeps `isset()` true and the raw body is never consulted.
     *
     * It has to be a KEPT listener to show this. For a dropped one there is
     * no reader left, so the detach hides whether the pin works at all —
     * which a mutation control demonstrated by surviving every other test in
     * this file.
     */
    public function testTheRawBodyCannotReachEvenAKeptListener(): void
    {
        $GLOBALS['__bcc_apg']['raw'] = ['anon_id' => '777'];

        PeepSoAddPostGuard::around(
            [PeepSoAddPostGuard::KEEP_PHOTOS],
            ['type' => 'photo', 'files' => ['abc.jpg'], 'module_id' => 4],
            static fn () => \PeepSoActivity::get_instance()->add_post(self::AUTHOR, self::AUTHOR, 'x')
        );

        self::assertContains(
            'PeepSoSharePhotos::after_add_post',
            $this->ran(),
            'the photo listener was dropped, so this says nothing about the raw body'
        );
        self::assertSame([], $GLOBALS['__bcc_apg']['anon'], 'the raw body reached a kept listener');
    }

    /**
     * ⚠ ANTI-VACUITY for the test above: show the raw body DOES reach that
     * listener when the key is merely absent rather than pinned. Without
     * this, the assertion could hold because nothing reads `anon_id` at all.
     */
    public function testTheFixtureRawBodyReachesAKeptListenerWhenNotPinned(): void
    {
        $GLOBALS['__bcc_apg']['raw'] = ['anon_id' => '777'];
        $_POST = ['type' => 'photo', 'files' => ['abc.jpg'], 'module_id' => 4];

        (new \PeepSoSharePhotos())->after_add_post(self::POST_ID, 777);

        self::assertSame([777], $GLOBALS['__bcc_apg']['anon']);
    }

    /**
     * ⚠ ANTI-VACUITY for the test above. If the fixture's raw-body leg were
     * dead, that test would pass against a guard that did nothing at all. So
     * drive the same listener WITHOUT the guard and show the stamp appears.
     *
     * This asserts the FIXTURE, deliberately — it is the control that gives
     * the previous test its meaning.
     */
    public function testTheFixtureRawBodyLegIsLive(): void
    {
        $GLOBALS['__bcc_apg']['raw'] = ['group_id' => (string) self::SECRET_HALL, 'module_id' => '8'];

        (new \PeepSoGroupsPlugin())->after_add_post(self::POST_ID, 777);

        self::assertSame(
            self::SECRET_HALL,
            $this->meta()['peepso_group_id'] ?? null,
            'the fixture cannot read the raw body, so the raw-body test proves nothing'
        );
    }

    // ── what must keep working ──────────────────────────────────────────

    /**
     * F-423: PeepSo's own @mention listener never runs on a BCC write. It
     * mailed and belled every mentionee gated only on the AUTHOR's profile
     * privacy, never the post's audience. BCC's NotificationDispatcher owns
     * mentions. The listener is back on the hook afterwards.
     */
    public function testPeepSosOwnMentionListenerNeverRuns(): void
    {
        $before = $this->registry();

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello @someone');

        self::assertNotContains('PeepSoTags::after_save_post', $this->ran());
        self::assertSame($before, $this->registry(), 'restored afterwards');
    }

    /**
     * ⚠ And no keep-list can keep it, so a writer this suite cannot drive
     * (photo, GIF) or a future one cannot bring the mail back. The control
     * keeps a neighbour on the same hook: the keep-list itself still works.
     */
    public function testNoKeepListCanKeepPeepSoTags(): void
    {
        PeepSoAddPostGuard::around(
            [['PeepSoTags', 'after_save_post'], ['PeepSoPolls', 'after_add_post']],
            [],
            static fn () => \PeepSoActivity::get_instance()->add_post(self::AUTHOR, self::AUTHOR, 'hello @someone')
        );

        self::assertNotContains('PeepSoTags::after_save_post', $this->ran());
        self::assertContains('PeepSoPolls::after_add_post', $this->ran(), 'control: a kept listener still runs');
    }

    /**
     * ⚠ ANTI-VACUITY for the whole file: BCC's OWN group route still works.
     * Without this, a guard that refused every group post would pass
     * everything above.
     */
    public function testBccsOwnGatedGroupPostIsStillStamped(): void
    {
        $result = PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello', 1234, 'members_only');

        self::assertSame(true, $result['ok'] ?? null);
        // ⚠ A STRING: attachToGroup() stores `(string) $groupId`, which is
        // what WordPress hands back from post-meta too.
        self::assertSame('1234', $this->meta()['peepso_group_id'] ?? null);
        self::assertSame('members_only', $this->meta()['_bcc_post_visibility'] ?? null);
    }

    // ── the other vectors on the same hook ──────────────────────────────

    /**
     * The blind SSRF. `handle_embed_data` reads `embed` and fetches it.
     *
     * ⚠ `embed` comes from `$_POST`, never `$_GET` — `get_raw()` at
     * input.php:89 is an empty stub — so this vector needs a form-encoded
     * request, not a query string. Modelled that way on purpose.
     */
    public function testNoOutboundFetchForAStrayEmbedParameter(): void
    {
        $_POST['type']  = 'activity';
        $_POST['embed'] = 'http://169.254.169.254/latest/meta-data/';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame([], $GLOBALS['__bcc_apg']['fetched'], 'the embed fetcher ran');
    }

    public function testNoPollOrBackgroundMetaFromStrayParameters(): void
    {
        $_GET['options']    = ['a', 'b'];
        $_GET['background'] = 'url(https://evil.example/x.png)';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertArrayNotHasKey('select_options', $this->meta());
        self::assertArrayNotHasKey('peepso_post_background', $this->meta());
    }

    public function testNoAlbumIsCreatedFromStrayParameters(): void
    {
        $_GET['type']  = 'album';
        $_GET['photo'] = ['x.jpg'];
        $_GET['name']  = 'Planted album';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame([], $GLOBALS['__bcc_apg']['albums']);
    }

    /**
     * ⭐ THE CASE DETACHING CANNOT COVER, and the reason the pin is the
     * PRIMARY control rather than a belt.
     *
     * The photo writer must KEEP `PeepSoSharePhotos::after_add_post`, because
     * that listener is what attaches its upload. And that listener's
     * `save_images()` reads `anon_id` (photosmodel.php:100) and copies the
     * image plus thumbnails into THAT member's photo folder. The listener is
     * kept, so no detach can close this — only pinning `anon_id` to 0 does.
     */
    public function testAnonIdCannotPlantAnUploadInAnotherMembersFolder(): void
    {
        $_GET['anon_id'] = '777';

        PeepSoAddPostGuard::around(
            [PeepSoAddPostGuard::KEEP_PHOTOS],
            ['type' => 'photo', 'files' => ['abc.jpg'], 'module_id' => 4],
            static fn () => \PeepSoActivity::get_instance()->add_post(self::AUTHOR, self::AUTHOR, 'x')
        );

        self::assertContains(
            'PeepSoSharePhotos::after_add_post',
            $this->ran(),
            'the photo listener was dropped, so this says nothing about anon_id'
        );
        self::assertSame(['abc.jpg'], $this->meta()['photos'] ?? null, 'the upload was not attached');
        self::assertSame([], $GLOBALS['__bcc_apg']['anon'], 'the image was copied into another member\'s folder');
    }

    /**
     * ⚠ Request input must not beat the values a writer PINS. This is the
     * sharpest form of it: the photo writer sets a routing value to drive
     * PeepSo's photo path, and request input used to outrank it.
     */
    public function testAQueryStringCannotOverrideAPinnedValue(): void
    {
        $_GET['module_id'] = '8';
        $_GET['type']      = 'activity';

        PeepSoAddPostGuard::around(
            [PeepSoAddPostGuard::KEEP_PHOTOS],
            ['type' => 'photo', 'files' => ['abc.jpg'], 'module_id' => 4],
            static fn () => \PeepSoActivity::get_instance()->add_post(self::AUTHOR, self::AUTHOR, 'x')
        );

        $call = $GLOBALS['__bcc_apg']['add_post'][0] ?? [];
        self::assertIsArray($call);
        self::assertSame([], $call['get'] ?? null, '$_GET was still populated during the write');
        self::assertSame(4, $call['post']['module_id'] ?? null);
        self::assertSame('photo', $call['post']['type'] ?? null);
        self::assertSame(['abc.jpg'], $this->meta()['photos'] ?? null);
    }

    // ── restoration ─────────────────────────────────────────────────────

    public function testTheHookRegistryIsRestoredExactly(): void
    {
        $before = $this->registry();

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame($before, $this->registry());
    }

    public function testTheRequestIsRestoredExactly(): void
    {
        $_GET     = ['group_id' => '1', 'keep' => 'me'];
        $_POST    = ['type' => 'activity', 'other' => 'value'];
        $_REQUEST = ['merged' => 'yes'];

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame(['group_id' => '1', 'keep' => 'me'], $_GET);
        self::assertSame(['type' => 'activity', 'other' => 'value'], $_POST);
        self::assertSame(['merged' => 'yes'], $_REQUEST);
    }

    /**
     * ⚠ Both restores run through a `finally`, so a throwing write must not
     * leave the hook registry gutted or the request pinned for whatever runs
     * next in the same PHP process.
     */
    public function testEverythingIsRestoredWhenTheWriteThrows(): void
    {
        $before   = $this->registry();
        $_GET     = ['keep' => 'me'];
        $_POST    = ['keep' => 'me too'];
        $_REQUEST = ['keep' => 'as well'];

        try {
            PeepSoAddPostGuard::around(
                [PeepSoAddPostGuard::KEEP_PHOTOS],
                [],
                static function (): int {
                    throw new \RuntimeException('add_post blew up');
                }
            );
            self::fail('the exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('add_post blew up', $e->getMessage());
        }

        self::assertSame($before, $this->registry());
        self::assertSame(['keep' => 'me'], $_GET);
        self::assertSame(['keep' => 'me too'], $_POST);
        self::assertSame(['keep' => 'as well'], $_REQUEST);
    }

    // ── the backstop ────────────────────────────────────────────────────

    /**
     * If a stamp is present anyway — a listener we failed to detach, or one a
     * future PeepSo release adds — the post is destroyed and the write fails
     * rather than being served as legitimate.
     *
     * ⚠ Deletion goes through PeepSo's own `delete_post()` and not
     * `wp_delete_post()`, which would leave the `peepso_activities` row
     * behind (PeepSo's `before_delete_post` cleanup only covers BLOG posts).
     */
    public function testAStampedPostIsDeletedThroughPeepSosOwnPath(): void
    {
        $this->seedStamp(self::POST_ID, 'peepso_group_id', self::SECRET_HALL);

        $reason = PeepSoAddPostGuard::failClosedIfHijacked(self::POST_ID, 0);

        self::assertSame('hijacked', $reason);
        self::assertSame([self::POST_ID], $GLOBALS['__bcc_apg']['deleted']);
        self::assertNotSame([], $GLOBALS['__bcc_apg']['logged'], 'a hijack must leave a log trail');
    }

    public function testACleanPostIsLeftAlone(): void
    {
        $reason = PeepSoAddPostGuard::failClosedIfHijacked(self::POST_ID, 0);

        self::assertNull($reason);
        self::assertSame([], $GLOBALS['__bcc_apg']['deleted']);
    }

    /**
     * The writer WIRES the backstop, which no assertion on the guard alone
     * can show.
     *
     * ⚠ The stamp is seeded before the call, which is artificial — the real
     * sequence is a listener stamping during `add_post`. What it pins is the
     * writer's wiring: that it calls the backstop, honours a non-null answer,
     * and does so BEFORE `attachToGroup()` applies BCC's own stamp.
     */
    public function testTheStatusWriterRefusesAHijackedPost(): void
    {
        $this->seedStamp(self::POST_ID, 'peepso_group_id', self::SECRET_HALL);

        $result = PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame(false, $result['ok'] ?? null);
        self::assertSame('hijacked', $result['reason'] ?? null);
        self::assertSame([self::POST_ID], $GLOBALS['__bcc_apg']['deleted']);
    }

    /**
     * ⛔⛔ F-498 — A READ THAT CANNOT BE TRUSTED IS A HIJACK.
     *
     * The old check called `get_post_meta()` and skipped an empty value. That
     * returns `''` for a key that is NOT THERE and for a read that DID NOT
     * RUN, so an unreadable meta table made this backstop report the post
     * clean — the one state where it most needed to refuse.
     *
     * Both modes come from the shared double: `error` is a driver failure
     * (`query()` false with `last_error` set), `preflush` is the bail that
     * leaves the PREVIOUS query's rows in `last_result` with NO `last_error`,
     * which is the one a reader trusting `get_results()` plus `last_error`
     * would serve as a real answer.
     *
     * ⚠ NOTHING IS SEEDED HERE, deliberately. The post is clean, so the only
     * thing that can produce a refusal is the read being untrustworthy. If the
     * guard ever fell back to trusting an empty answer, these two fail.
     */
    #[DataProvider('untrustedReads')]
    public function testAnUntrustedStampReadIsTreatedAsAHijack(string $mode): void
    {
        $this->metaReadModes($mode);

        $reason = PeepSoAddPostGuard::failClosedIfHijacked(self::POST_ID, 0);

        self::assertSame('hijacked', $reason, 'an untrusted read must not pass as clean');
        self::assertSame([self::POST_ID], $GLOBALS['__bcc_apg']['deleted'], 'the post must be destroyed');
        self::assertNotSame([], $GLOBALS['__bcc_apg']['logged'], 'and it must leave a log trail');
    }

    /** @return iterable<string, array{string}> */
    public static function untrustedReads(): iterable
    {
        yield 'a driver failure'   => ['error'];
        yield 'the pre-flush bail' => ['preflush'];
    }

    /**
     * ⚠ ANTI-VACUITY for the two cases above, and the reason they are worth
     * having. A clean post with a read that DID run must come back null — so
     * neither "always hijacked" nor "the fixture never reaches the code" can
     * explain a pass. It also proves the query really happened.
     */
    public function testACleanPostWithATrustedReadIsLeftAlone(): void
    {
        $this->metaReadModes('ok');

        $reason = PeepSoAddPostGuard::failClosedIfHijacked(self::POST_ID, 0);

        self::assertNull($reason);
        self::assertSame([], $GLOBALS['__bcc_apg']['deleted']);

        self::assertGreaterThan(0, $GLOBALS['wpdb']->metaQueries, 'the guarded read must actually query');
    }

    /**
     * ⛔⛔ F-498 — THE COMMENT BACKSTOP TAKES NO READ AT ALL, and that is the
     * fix rather than a shortcut.
     *
     * BCC never writes any of these keys on a comment, so there is nothing to
     * preserve and no verdict to get wrong: deleting a key that was never set
     * is a no-op. Scripting EVERY meta query to fail proves the absence — a
     * version that still read would strip nothing here.
     *
     * ⚠ It also pins the asymmetry with the post backstop above, which does
     * read, because deleting a POST has to be a decision.
     */
    public function testTheCommentBackstopStripsEvenWhenEveryReadFails(): void
    {
        $this->seedStamp(self::POST_ID, 'peepso_post_as_page', '1');
        $this->seedStamp(self::POST_ID, 'peepso_page_id', '77');
        $this->metaReadModes('error', 'error', 'error', 'error');

        $reason = PeepSoAddPostGuard::stripForgedStamps(self::POST_ID);

        self::assertSame('forged_stamp_stripped', $reason);
        self::assertArrayNotHasKey('peepso_post_as_page', $this->meta(), 'the forged stamp must be gone');
        self::assertArrayNotHasKey('peepso_page_id', $this->meta(), 'and the id it rides on');
        self::assertSame([], $GLOBALS['__bcc_apg']['deleted'], 'a comment is never destroyed');
    }

    /**
     * ⚠ THE OTHER HALF, and the one an always-true delete stub would hide. A
     * clean comment must report NOTHING stripped, or every ordinary comment
     * logs a forgery. `delete_post_meta()` returns false when no row matched,
     * which is what distinguishes the two — see the stub's note.
     */
    public function testACleanCommentReportsNothingStripped(): void
    {
        self::assertNull(PeepSoAddPostGuard::stripForgedStamps(self::POST_ID));
        self::assertSame([], $GLOBALS['__bcc_apg']['logged'], 'a clean comment must not log a forgery');
    }

    // ── the keep-list itself ────────────────────────────────────────────

    /**
     * A listener nobody has enumerated — a future PeepSo add-on — is dropped
     * by default. That is what makes the keep-list an allowlist rather than a
     * list of today's known offenders.
     */
    public function testAnUnknownListenerIsDroppedByDefault(): void
    {
        \__bcc_apg_add(
            'peepso_activity_after_add_post',
            static function (): void {
                $GLOBALS['__bcc_apg']['ran'][] = 'future-addon';
            },
            10,
            2
        );

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertNotContains('future-addon', $this->ran());
    }
}
