<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ⚠⚠ THE ONE SAFE WAY FOR BCC TO CALL `PeepSoActivity::add_post()`.
 *
 * ── THE BUG THIS EXISTS FOR ──────────────────────────────────────────
 *
 * `add_post()` fires `peepso_activity_after_add_post`, and EVERY listener
 * on that hook except one reads the CURRENT REQUEST to decide what to do,
 * with no authorization check of its own — because in PeepSo's own UI the
 * request was shaped by PeepSo's own form.
 *
 * ⚠⚠ AND `after_add_post` IS NOT THE ONLY PLACE. That hook runs AFTER the
 * row exists; others run DURING the same write, including the CORE WordPress
 * `wp_insert_post` hook and a FILTER on the row about to be written — one of
 * which can override the audience BCC passed in `$extra` (the F-291
 * default-audience fix). Two of these were missed in the first pass, which
 * is why the guard covers the WRITE and not one hook.
 *
 * ⚠ The per-listener inventory — which listener, at which line, reading
 * which key — is with the security review (private tracker). This repo is public.
 * {@see PINNED_KEYS} is the operative list.
 *     PeepSoSharePhotos::activity_insert_data  — rewrites act_module_id
 *
 * ⚠ None of those can be DETACHED safely — `wp_insert_post` is WordPress's
 * own hook, and the photos filter is what the photo writer depends on. The
 * PIN is the only control that reaches them, which is why it is the primary
 * one and why {@see pinOnly()} exists for writes that are a bare
 * `wp_insert_post()` with no `add_post()` around them.
 *
 * The comment path (`add_comment`, activity.php:364) has the same shape on
 * {@see HOOK_COMMENT}: `PeepSoPagesPlugin::after_add_comment` (pages.php:3097)
 * reads `post_as_page` and can make any member's comment render as authored
 * BY the page, and the photos and giphy comment listeners read the request
 * too. Its content-driven listeners are the three KEEP_COMMENT_* entries.
 *
 * ⚠ `PeepSoTags::after_save_post` (tags.php:127) and `after_save_comment`
 * (tags.php:201) are detached too, although they read no request input
 * (F-423). They mail "Someone mentioned you" and write a PeepSo-module bell
 * for every @mention in the content, gated only on
 * `PeepSo::check_permissions(PERM_POST_VIEW, author)`, which is the
 * AUTHOR's profile privacy. That is not the post's audience: a secret or
 * closed group post, a followers-only post or a DM mailed members who
 * cannot open it. BCC's own NotificationDispatcher owns mentions, gated on
 * the post's permalink. Mention RENDERING is unaffected: it is PeepSo's
 * `do_tags` content filter and BCC's MentionExtractor, both reading the
 * stored content, never these listeners.
 *
     * ⚠⚠ AND PeepSo's INPUT HELPERS DO NOT READ ONLY WHAT BCC SET. One of
     * them also falls back to the raw request body for a key absent from the
     * usual places, which is why a key has to be PINNED to a neutral value
     * rather than unset — the next section depends on exactly that.
     *
     * Two consequences, and the second is the one that is easy to miss:
     *
     *   1. Request input BCC never set can reach a listener, so a listener
     *      can stamp post-meta that BCC's own gates never authorised. That
     *      meta is read across the codebase to decide where a post belongs,
     *      so a value arriving this way is treated as fact from then on.
     *
     *   2. It also outranks the `$_POST` values OUR OWN WRITERS SET to drive
     *      PeepSo's paths, so the integration surface the writers depend on
     *      is not trustworthy on its own. That is why clearing the
     *      superglobals is part of this guard and not a separate nicety.
     *
     * ⚠ Deliberately not spelled out here: the specific parameters, their
     * precedence, and a worked request. This repo is public — that detail is
     * with the security review (private tracker).
 *
 * ── PIN THE INPUT, THEN DETACH ───────────────────────────────────────
 *
 * ⚠⚠ UNSETTING A KEY IS NOT ENOUGH, AND IS WORSE THAN USELESS. `post()`
 * returns its `$default` when `!isset($_POST[$name])`, and `int()`'s default
 * IS `raw_input()` — so `unset($_POST['group_id'])` is exactly what makes
 * `int()` fall through to `parse_str(file_get_contents('php://input'))`, a
 * stream no superglobal hygiene can reach. The key must therefore be PINNED
 * to a neutral value, not removed: `isset()` is then true, `post()` answers
 * with the pin, and the raw body is never consulted.
 *
 * So the guard pins rather than sanitises:
 *
 *   `$_GET`     emptied — it outranks everything
 *   `$_REQUEST` emptied — not a `PeepSoInput` source, but other PeepSo code
 *                         reads it, and emptying it costs nothing
 *   `$_POST`    REPLACED by {@see PINNED_KEYS} (every key these listeners
 *               read, at a neutral value) merged with exactly what the
 *               calling writer means to say
 *
 * ⚠ `files`, `photo` and `options` pin to `[]` and NOT `''`, because their
 * readers do `count($input->value('files', array()))`. A string there is a
 * `TypeError` in PHP 8 — a pin that fatals is not a safe default.
 *
 * Pinning also covers listeners nobody has enumerated yet, including ones a
 * future PeepSo release adds, which is what makes it the primary control and
 * the detach below defence in depth.
 *
 * The keep-list is an ALLOWLIST, not a denylist, for the same reason: a
 * listener PeepSo adds in a future release is dropped by default rather than
 * silently trusted. The cost is that a new PeepSo feature BCC wants has to be
 * named here — a visible, reviewable line — instead of arriving unannounced.
 *
 * ⚠ Both halves are needed, and `anon_id` is the clearest proof. The photo
 * writer must KEEP `PeepSoSharePhotos::after_add_post`, and that listener's
 * `save_images()` reads `anon_id` (peepso-photos/classes/photosmodel.php:100)
 * and copies the uploaded image plus its thumbnails into THAT member's photo
 * folder. Detaching cannot close it, because the listener is one we keep;
 * only the pin closes it.
 *
 * ── WHAT THIS CANNOT DO ──────────────────────────────────────────────
 *
 * {@see failClosedIfHijacked()} is a BACKSTOP for a detach that did not work,
 * not the fix. By the time it runs, a listener that was not detached has
 * already fired its notifications, and no post-hoc check can recall those.
 * The detach is the fix; the check only ensures we do not then SERVE the
 * hijacked post as though it were legitimate.
 *
 * ── §11 ──────────────────────────────────────────────────────────────
 *
 * The hook-enumeration shape — the `is_object` / `isset(->callbacks)` guards
 * and `remove_action($hook, $fn, (int) $priority)` — is taken from
 * {@see \BCC\Core\Admin\ForeignNoticeFilter::filter()}, which already walks
 * `$wp_filter[$hook]->callbacks` and removes selected callbacks on the
 * admin-notice hooks. That file is the in-tree precedent for this technique;
 * what is NOT reused is its selection rule (origin-file attribution, which
 * fails open) because every listener here is a third party's and this guard
 * must fail CLOSED.
 *
 * ⚠ Callbacks are removed and re-added INDIVIDUALLY rather than by swapping
 * the `WP_Hook` object out and back. That is what makes the guard safe to
 * run while PeepSo has a TEMPORARY registration of its own open —
 * peepso-groups/groups.php:2195/:2228 and peepso-pages/pages.php:3849 each
 * add an `after_add_post` callback, do a thing, and remove it again. A
 * snapshot-and-replace would restore a hook object captured mid-window and
 * could resurrect a callback the vendor had since removed, or drop one it
 * had since added.
 *
 * The superglobal save/restore half FULFILS the extraction
 * `PeepSoGifWriter::restorePostKey()` pre-registered in its own docblock —
 * "Duplicated from PeepSoPhotoWriter::restorePostKey (~6 lines). Acceptable
 * per §11 on second occurrence; if a third writer ever adds the same pattern,
 * extract to a shared `PostSuperglobalGuard` helper at that point." The third
 * caller has arrived ({@see PeepSoStatusWriter}, plus the repost writer), so
 * the extraction is due. It lands here rather than in a separate
 * `PostSuperglobalGuard` because the two halves must be ORDERED — the keys
 * are cleared BEFORE the writer sets its own, or the guard would wipe the
 * values the writer just wrote. It is PeepSo-specific (PeepSo's hook,
 * PeepSo's request keys, PeepSo's delete path), so it belongs beside the
 * writers in `src/PeepSo/` rather than in `src/Support/`.
 *
 * ⚠ THIS DOES NOT CONTRADICT {@see PeepSoStatusWriter}'s standing decision.
 * That writer's docblock records that driving PeepSo's routing keys through
 * the request superglobals was considered and REJECTED because it would
 * "leak group state into request superglobals". It still is rejected: this
 * guard never
 * WRITES a routing value into a superglobal — it CLEARS the ones an attacker
 * may have supplied. The status writer stays superglobal-free in the sense
 * that decision meant, and keeps stamping groups through
 * {@see PeepSoStatusWriter::attachToGroup()}.
 *
 * ⚠ The callable runs INSIDE this guard's `try`, so a writer that needs to
 * clean up on failure keeps its own `try/catch` within the callable — see
 * {@see PeepSoPhotoWriter}, which must unlink staged temp files on a throw
 * and rethrow. The guard adds a `finally`; it does not take one away.
 *
 * @package BCC_Core
 * @subpackage PeepSo
 */
final class PeepSoAddPostGuard
{
    /** The hook every request-driven PeepSo POST listener hangs off. */
    public const HOOK = 'peepso_activity_after_add_post';

    /**
     * The comment equivalent, fired by `add_comment()` (activity.php:364+).
     *
     * ⚠ Its listeners take FOUR arguments, not two, which is why
     * `accepted_args` is carried through the detach and restored rather than
     * assumed — a re-registration at the wrong arity silently changes what a
     * listener sees.
     */
    public const HOOK_COMMENT = 'peepso_after_add_comment';

    /**
     * Classes NO caller may keep, whatever its keep-list says.
     *
     * `PeepSoTags` (F-423): its two listeners mail and bell every @mention
     * gated on the author's profile privacy, not the post's audience; see
     * the class note. Refusing it HERE, not just leaving it off each
     * writer's list, is what covers the writers no test can drive (photo,
     * GIF) and any writer added later.
     */
    private const NEVER_KEPT = ['PeepSoTags'];

    /** Giphy post-meta — kept only by {@see PeepSoGifWriter}. */
    public const KEEP_GIPHY = ['PeepSoGiphy', 'after_add_post'];

    /** Single-photo attach — kept only by {@see PeepSoPhotoWriter}. */
    public const KEEP_PHOTOS = ['PeepSoSharePhotos', 'after_add_post'];

    // ── the comment hook's keep-list ────────────────────────────────────

    /**
     * `PeepSoActivityStream::activity_after_add_comment`
     * (peepso/activity/activitystream.php:260) bumps the ROOT post's
     * `post_modified`. Derived entirely from the comment row — it reads no
     * request input — and feeds "recently active" ordering, so dropping it
     * would quietly stop threads resurfacing when they get a reply.
     */
    public const KEEP_COMMENT_BUMP = ['PeepSoActivityStream', 'activity_after_add_comment'];

    /**
     * `PeepSo3_Anon::save_comments_anon_op` (peepso/3/classes/users/anon.php:131)
     * carries the "original poster is anonymous" marker onto a reply. It is
     * gated on the ROOT post's own meta matching the current user, not on
     * anything in the request, so it is not a hijack surface — and dropping
     * it would de-anonymise an anonymous OP's replies.
     */
    public const KEEP_COMMENT_ANON_OP = ['PeepSo3_Anon', 'save_comments_anon_op'];

    /**
     * Every request key PeepSo's listeners and pre-insert filters read,
     * mapped to the NEUTRAL value it is pinned to for the write.
     *
     * ⚠ Pinned, not unset — see the class docblock: unsetting is what opens
     * the raw-body fallback.
     *
     * ⚠ `files`, `photo` and `options` MUST pin to `[]`. Their readers call
     * `count()` on the value, and `count('')` is a `TypeError` in PHP 8.
     *
     * ⚠ `embed` is pinned even though `raw()` never consults `$_GET`
     * (`get_raw()` at peepso/classes/input.php:89 is an empty stub): it is
     * read from `$_POST`, which the pin replaces wholesale.
     *
     * @var array<string, string|int|list<mixed>>
     */
    private const PINNED_KEYS = [
        // routing / ownership — the authorization-relevant ones
        'group_id'       => 0,
        'page_id'        => 0,
        'post_as_page'   => 0,
        'module_id'      => 0,
        // ⚠ copies the upload into ANY member's photo folder
        // (peepso-photos/classes/photosmodel.php:100)
        'anon_id'        => 0,
        'post_id'        => 0,
        // media dispatch
        'type'           => '',
        'giphy'          => '',
        'embed'          => '',
        'context'        => '',
        'files'          => [],
        'photo'          => [],
        // album creation (PeepSoSharePhotos::after_add_post_album)
        'name'           => '',
        'description'    => '',
        'privacy'        => 0,
        // polls
        'options'        => [],
        'allow_multiple' => 0,
        // post backgrounds
        'background'     => '',
        'text_color'     => '',
        'preset_id'      => '',
        // ⚠⚠ READ ON `wp_insert_post`, NOT on an after-the-fact action, so
        // these run inside add_post() AND inside any bare wp_insert_post().
        // Two were missed in the first pass and are a real gap, since
        // `wp_insert_post` is a CORE hook that can never be detached.
        'mood'           => 0,
        'location'       => '',
    ];

    /**
     * Post-meta that decides WHERE a post lives, i.e. who may read it.
     *
     * ⚠ Only the authorization-relevant stamps are checked. Poll options and
     * background colours can also be injected by a listener that escaped the
     * detach, but they are cosmetic meta on the member's own post — they
     * change no audience and leak nothing, so treating them as a hijack
     * would fail legitimate writes for no security gain. Stated rather than
     * left as an apparent oversight.
     *
     * @var list<string>
     */
    private const AUTHORIZATION_STAMPS = [
        'peepso_group_id',
        'peepso_page_id',
        'peepso_post_as_page',
    ];

    /**
     * Run $write with PeepSo's request-driven listeners detached and the
     * hijackable request keys cleared, restoring both in a `finally`.
     *
     * @template TResult
     * @param list<array{0: string, 1: string}> $keep     [class, method] pairs
     *                                                    to retain on the
     *                                                    hook; use the KEEP_*
     *                                                    constants.
     * @param array<string, mixed>              $intended the `$_POST` this
     *                                                    writer MEANS to say,
     *                                                    merged over the
     *                                                    neutral pins
     * @param callable(): TResult               $write    the add_post call
     * @return TResult whatever $write returns
     */
    public static function around(array $keep, array $intended, callable $write)
    {
        return self::aroundHooks([self::HOOK], $keep, $intended, $write);
    }

    /**
     * PIN-ONLY: no hook is touched, the request is still neutralised.
     *
     * ⚠ For a write that is a bare `wp_insert_post()` rather than an
     * `add_post()`. The request is PINNED so that listeners on
     * `wp_insert_post` cannot act on this request's input. The per-listener
     * inventory is with the security review (private tracker).
     *
     * ⚠⚠ DETACHING IS NOT AN OPTION HERE, and that is the whole reason this
     * mode exists. `wp_insert_post` is a CORE WordPress hook: WordPress
     * itself, BCC and every other plugin hang off it, so emptying it around a
     * write would break far more than it protected. Pinning the request
     * removes what those three listeners READ without touching what anyone
     * RUNS.
     *
     * ⚠ TO WRAP A **VOID** WRITE, USE A BLOCK CLOSURE THAT RETURNS A VALUE.
     * `TResult` below cannot be resolved from a void callable, so an arrow
     * fn over something like `PeepSoGroup::update()` fails PHPStan at level
     * 8 with "Unable to resolve the template type TResult". Return a plain
     * `true` and say in a comment that the value claims nothing about
     * success — the void method's own failure handling is unchanged by the
     * pin, so a return value here would be inventing a signal the write
     * never gave. {@see PeepSoGroupWriter::transferOwnership()} does this.
     *
     * ⚠ The write MUST sit inside the closure, not be passed as an
     * evaluated argument: `pinOnly([], wp_insert_post(...))` evaluates the
     * write BEFORE this method runs, so it is not pinned at all. Both
     * plugins' `PostWritePinInventoryTest` rejects that form.
     *
     * @template TResult
     * @param array<string, mixed> $intended
     * @param callable(): TResult  $write
     * @return TResult
     */
    public static function pinOnly(array $intended, callable $write)
    {
        return self::aroundHooks([], [], $intended, $write);
    }

    /**
     * The same guard over an explicit set of hooks.
     *
     * ⚠ Separate from {@see around()} rather than a fourth parameter on it,
     * so the three post writers' call sites stay exactly as reviewed. The
     * comment and message paths reach DIFFERENT hooks:
     *
     *   `add_comment()`                 → `peepso_after_add_comment`
     *   `create_new_conversation()`     → `peepso_activity_after_add_post`,
     *   `add_to_conversation()`           fired DIRECTLY by
     *                                     peepso-messages/classes/messagesmodel.php:321
     *                                     and :500 — those two never call
     *                                     `add_post()` at all, so the hook is
     *                                     reached without the function that
     *                                     normally fires it.
     *
     * @template TResult
     * @param list<string>                      $hooks    hook names to guard
     * @param list<array{0: string, 1: string}> $keep     [class, method] pairs
     *                                                    retained across ALL
     *                                                    the named hooks
     * @param array<string, mixed>              $intended the `$_POST` this
     *                                                    writer means to say
     * @param callable(): TResult               $write
     * @return TResult
     */
    public static function aroundHooks(array $hooks, array $keep, array $intended, callable $write)
    {
        $request  = self::pinRequest($intended);

        /** @var array<string, list<array{0: mixed, 1: int, 2: int}>> $detached */
        $detached = [];
        foreach ($hooks as $hook) {
            $detached[$hook] = self::detachListenersExcept($hook, $keep);
        }

        try {
            return $write();
        } finally {
            // Hooks first: the restored request must never be visible to a
            // listener that is still detached, nor the reverse.
            foreach ($detached as $hook => $callbacks) {
                self::reattach($hook, $callbacks);
            }
            self::restoreRequest($request);
        }
    }

    /**
     * BACKSTOP. If a post came back carrying an authorization stamp BCC did
     * not intend, delete it through PeepSo's own delete path and say so.
     *
     * ⚠ Deletion goes through `PeepSoActivity::delete_post()` (the wp_post
     * id, activity.php:777), NOT `wp_delete_post()`. PeepSo's
     * `before_delete_post` cleanup (peepso.php:2887) only fires for BLOG
     * posts, keyed on the blogposts-shortcode meta, so a `wp_delete_post()`
     * here would leave an ORPHAN activity row pointing at a missing wp_post.
     *
     * @param int $postId          the wp_post id add_post returned
     * @param int $intendedGroupId the group BCC gated and intends to stamp
     *                             ITSELF afterwards, or 0 for a profile post
     * @return string|null a failure reason, or null when the post is clean
     */
    public static function failClosedIfHijacked(int $postId, int $intendedGroupId = 0): ?string
    {
        if ($postId <= 0) {
            return null;
        }

        // ⛔⛔ F-498 — A GUARDED TRI-STATE READ, AND "COULD NOT TELL" IS
        // TREATED AS A HIJACK.
        //
        // This used to call `get_post_meta()` and `continue` on an empty
        // value. `get_post_meta()` cannot tell "absent" from "unread", so
        // an unreadable meta table made this backstop report the post clean
        // — the one state where it most needed to refuse. Re-reading does
        // not help: the same answer comes back for the rest of the request.
        //
        // `valuesOrUnknown()` reads `wp_postmeta` directly through
        // ProbesReads, so it never consults that cache, and answers `null`
        // for a read it cannot vouch for. Here `null` takes the SAME branch
        // as a stamp that is actually present.
        //
        // ⚠ THE COMMENT BACKSTOP DOES NOT NEED THIS, and the asymmetry is
        // deliberate. {@see stripForgedStamps()} deletes unconditionally
        // because deleting a key that was never set is a no-op. Deleting a
        // POST is not: it has to be a decision, so this one reads.
        //
        // ⚠ SINGLE-VALUED KEYS ONLY — the repository's own caveat, and it
        // is satisfied with room to spare rather than by assumption. The
        // chunk LIMIT is `ids × keys × MAX_ROWS_PER_PAIR`, so for one post
        // and three keys it is 1 × 3 × 5 = 15 rows, and the read answers
        // `null` at 15 or more. BCC writes at most ONE row here
        // (`update_post_meta()` cannot duplicate a key) and a listener that
        // got there first adds one or two, so the ceiling is unreachable on
        // a just-created post. If it ever were reached the answer is `null`,
        // which fails closed.
        $read = \BCC\Core\Repositories\PostMetaRepository::valuesOrUnknown(
            [$postId],
            self::AUTHORIZATION_STAMPS
        );

        if ($read === null) {
            $stamps = 'UNKNOWN — the stamp read could not be trusted';
        } else {
            $found = [];
            foreach (self::AUTHORIZATION_STAMPS as $key) {
                foreach ($read[$postId][$key] ?? [] as $value) {
                    // ⛔⛔ NO `(int)` CAST. `valuesOrUnknown()` hands back RAW
                    // STRINGS, and a cast makes any non-numeric stamp read as
                    // zero and get SKIPPED — a serialized value casts to 0
                    // ('a:1:{...}' is not a number), so the post would come
                    // back clean. A backstop exists to catch what nobody
                    // enumerated, which is exactly what a loose cast undoes.
                    //
                    // So: ANY value other than '' and '0' is a stamp. '0' is
                    // excluded because it is what `PINNED_KEYS` pins a numeric
                    // key to, i.e. the neutral value, not a stamp.
                    if ($value === '' || $value === '0') {
                        continue;
                    }
                    // BCC stamps the group itself, AFTER this check
                    // ({@see PeepSoStatusWriter::attachToGroup()}), so a
                    // matching group stamp here means a listener got there
                    // first — with the right id, but without BCC's gate
                    // having run. Still a hijack.
                    $found[] = $key . '=' . $value;
                }
            }

            if ($found === []) {
                return null;
            }

            $stamps = implode(',', $found);
        }

        // ⭐ ONE log-and-destroy path for both branches, so a future change
        // cannot fix one and forget the other.
        \BCC\Core\Log\Logger::error(
            '[bcc-core] PeepSo listener stamped a post BCC did not route — detach failed',
            [
                'post_id'           => $postId,
                'intended_group_id' => $intendedGroupId,
                'stamps'            => $stamps,
            ]
        );

        if (class_exists('PeepSoActivity')) {
            \PeepSoActivity::get_instance()->delete_post($postId);
        }

        return 'hijacked';
    }

    /**
     * BACKSTOP FOR A COMMENT: strip a forged authorship stamp, keep the
     * comment.
     *
     * ⚠ DELIBERATELY NOT {@see failClosedIfHijacked()}, and the difference is
     * not squeamishness. That one deletes through
     * `PeepSoActivity::delete_post()`, which also removes nested replies and
     * likes — the exact reason {@see PeepSoCommentWriter::deleteComment()}
     * trashes a comment's wp_post instead of calling it. Destroying a
     * member's legitimate comment, and anything hanging off it, is a worse
     * outcome than the thing being defended against.
     *
     * And nothing is left wrong by stripping: the only comment-level stamp a
     * request can forge is `peepso_post_as_page` (+ the `peepso_page_id` it
     * rides on), which makes the comment render as authored BY the page
     * (peepso-pages/pages.php:3097). Remove the meta and the forgery is
     * undone — the comment is the member's own words either way.
     *
     * ⚠ What this CANNOT undo, same as the post backstop: a notification a
     * listener already fired. The pin and the detach are the fix.
     *
     * @return string|null a reason when something was stripped, else null
     */
    public static function stripForgedStamps(int $postId): ?string
    {
        if ($postId <= 0) {
            return null;
        }

        // ⛔⛔ F-498 — DELETED UNCONDITIONALLY, WITH NO READ, AND THE
        // ABSENCE OF THE READ IS THE FIX.
        //
        // This used to call `get_post_meta()` first and `continue` on an
        // empty value. `get_post_meta()` cannot tell "absent" from
        // "unread", so an unreadable meta table took the `continue` and
        // this backstop reported the comment clean. So this backstop takes
        // NO read at all.
        //
        // A guarded tri-state read would fix that, and is what the POST
        // backstop needs, because deleting a post is destructive and has to
        // be deliberate. Here it is unnecessary: BCC never writes ANY of
        // these keys on a comment — measured across bcc-core, bcc-trust and
        // bcc-search, including a check for dynamic `update_post_meta()`
        // keys — so there is nothing to preserve and no verdict to get
        // wrong. Deleting a key that was never set is a no-op.
        //
        // ⚠ WHAT THIS DOES NOT CLOSE, stated rather than implied: a FAILED
        // LOOKUP OR WRITE INSIDE THE DELETE can leave a stamp. It is wider
        // than "the write failed": `delete_metadata()` finds the rows with
        // its own `get_col` first, so a failed SELECT there returns an empty
        // set, nothing is deleted, and `delete_post_meta()` reports false —
        // the same false it returns for "there was nothing to delete".
        // Telling those apart needs a read, which is what this change
        // removes. So the read-side fail-open on the DECISION is gone; a
        // failure inside the delete itself is not.
        $stripped = [];
        foreach (self::AUTHORIZATION_STAMPS as $key) {
            if (delete_post_meta($postId, $key)) {
                $stripped[] = $key;
            }
        }

        if ($stripped === []) {
            return null;
        }

        // ⚠ The key NAMES only. The old log carried `key=value`, which cost
        // a read; the decision no longer takes one, so neither does this.
        \BCC\Core\Log\Logger::error(
            '[bcc-core] PeepSo listener stamped a comment BCC did not route — detach failed',
            ['post_id' => $postId, 'stamps' => implode(',', $stripped)]
        );

        return 'forged_stamp_stripped';
    }

    // ── superglobals ────────────────────────────────────────────────────

    /**
     * Replace the request with exactly what BCC means to say.
     *
     * ⚠ The arrays are snapshotted and REPLACED WHOLESALE rather than edited
     * key by key, so nothing a caller forgot to list can survive. That is
     * safe here because BCC's REST layer reads its arguments from the parsed
     * `WP_REST_Request`, never from these superglobals, and the replacement
     * lasts only for the duration of one `add_post()` call.
     *
     * @param array<string, mixed> $intended
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     *         the previous [$_GET, $_POST, $_REQUEST]
     */
    private static function pinRequest(array $intended): array
    {
        $saved = [$_GET, $_POST, $_REQUEST];

        $_GET     = [];
        $_REQUEST = [];
        $_POST    = array_merge(self::PINNED_KEYS, $intended);

        return $saved;
    }

    /**
     * @param array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} $saved
     */
    private static function restoreRequest(array $saved): void
    {
        [$_GET, $_POST, $_REQUEST] = $saved;
    }

    // ── hooks ───────────────────────────────────────────────────────────

    /**
     * Remove every callback on $hook except the keep-list.
     *
     * @param list<array{0: string, 1: string}> $keep
     * @return list<array{0: mixed, 1: int, 2: int}> [callback, priority, acceptedArgs]
     */
    private static function detachListenersExcept(string $hook, array $keep): array
    {
        $detached = [];

        foreach (self::listenersByPriority($hook) as $priority => $callbacks) {
            foreach ($callbacks as $entry) {
                $fn = is_array($entry) ? ($entry['function'] ?? null) : null;
                if ($fn === null || !is_callable($fn)) {
                    continue;
                }
                if (self::isKept($fn, $keep)) {
                    continue;
                }

                $args = 1;
                if (is_array($entry) && isset($entry['accepted_args']) && is_int($entry['accepted_args'])) {
                    $args = $entry['accepted_args'];
                }

                remove_action($hook, $fn, (int) $priority);
                $detached[] = [$fn, (int) $priority, $args];
            }
        }

        return $detached;
    }

    /**
     * @param list<array{0: mixed, 1: int, 2: int}> $detached
     */
    private static function reattach(string $hook, array $detached): void
    {
        foreach ($detached as [$fn, $priority, $args]) {
            add_action($hook, $fn, $priority, $args);
        }
    }

    /**
     * One hook's callbacks, priority => (id => entry).
     *
     * Same defensive shape as
     * {@see \BCC\Core\Admin\ForeignNoticeFilter::filter()}: WordPress stores
     * a `WP_Hook` object here, but a plugin can leave anything in
     * `$wp_filter`, and an absent hook is the ordinary case on a request
     * where nothing registered.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private static function listenersByPriority(string $hook): array
    {
        /** @var array<string, mixed> $wpFilter */
        $wpFilter = $GLOBALS['wp_filter'] ?? [];
        if (!is_array($wpFilter) || !isset($wpFilter[$hook])) {
            return [];
        }

        $registry = $wpFilter[$hook];
        if (!is_object($registry) || !isset($registry->callbacks) || !is_array($registry->callbacks)) {
            return [];
        }

        /** @var array<int|string, array<string, mixed>> $byPriority */
        $byPriority = $registry->callbacks;

        return $byPriority;
    }

    /**
     * Whether $fn is on the keep-list, matched by CLASS and METHOD.
     *
     * ⚠ Matched by class name rather than by object identity, because PeepSo
     * registers with `array(&$this, 'method')` from a singleton this code
     * never holds a reference to. Class+method is also what survives PeepSo
     * re-instantiating a plugin object between requests.
     *
     * @param mixed                             $fn
     * @param list<array{0: string, 1: string}> $keep
     */
    private static function isKept($fn, array $keep): bool
    {
        if (!is_array($fn) || count($fn) !== 2 || !is_string($fn[1])) {
            return false; // a closure or function name is never kept
        }

        $class = is_object($fn[0]) ? get_class($fn[0]) : (is_string($fn[0]) ? $fn[0] : '');
        if ($class === '') {
            return false;
        }
        foreach (self::NEVER_KEPT as $neverKept) {
            if ($class === $neverKept || is_subclass_of($class, $neverKept)) {
                return false;
            }
        }

        foreach ($keep as [$keepClass, $keepMethod]) {
            if ($fn[1] === $keepMethod
                && ($class === $keepClass || is_subclass_of($class, $keepClass))
            ) {
                return true;
            }
        }

        return false;
    }
}
