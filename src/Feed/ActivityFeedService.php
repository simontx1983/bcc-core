<?php

namespace BCC\Core\Feed;

use BCC\Core\PeepSo\PeepSoGraphService;
use BCC\Core\Repositories\PeepSoActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single read-side access point for the personalized feed (§F1, §N6).
 *
 * Phase 1 contract: chronological ordering only. Ranking (the F1 priority
 * chain that drives `for_you`) is layered on top in Phase 2 by composing
 * BccFeedRankingService with this service — this service stays "what
 * happened, in time order, scoped to who you care about."
 *
 * Scopes:
 *   for_you   — every non-system post (chronological in V1; ranked in V2).
 *   following — strict-time-ordered posts authored by users the viewer follows.
 *   signals   — signal post-kinds only, chronological.
 *
 * Hydration: the service does the N+1-avoidant work — batch the rows, batch
 * the author lookups, batch the reactions, batch the social-proof composer.
 * The normalizer is a pure transform over already-hydrated data.
 *
 * Body-shape resolution per kind is intentionally LEFT TO THE NORMALIZER's
 * caller path (this service) because each kind reads from a different
 * source-of-truth (bcc_trust_votes for reviews, bcc_pull_meta for batches,
 * bcc_onchain_signals for signals, etc.). Phase 1 ships with the body
 * dispatcher returning {} for non-status kinds; subsequent phases fill them
 * in kind-by-kind without touching this service's signature.
 *
 * @phpstan-import-type ActivityRow from PeepSoActivityRepository
 */
final class ActivityFeedService
{
    public const SCOPE_FOR_YOU   = 'for_you';
    public const SCOPE_FOLLOWING = 'following';
    public const SCOPE_SIGNALS   = 'signals';
    public const SCOPE_GROUP     = 'group';

    /** @var list<string> */
    public const VALID_SCOPES = [self::SCOPE_FOR_YOU, self::SCOPE_FOLLOWING, self::SCOPE_SIGNALS];

    private PeepSoGraphService $graph;

    public function __construct(PeepSoGraphService $graph)
    {
        $this->graph = $graph;
    }

    /**
     * @param int $viewerId 0 for anonymous viewers (only valid on hot-feed contexts; per contract /feed requires auth).
     * @param list<int>|null $excludedAuthorIds Optional shadow-limit list (per §O4.1). Caller (bcc-trust's FeedRankingService) precomputes this; bcc-core does not know about reputation tiers.
     * @param list<int>|null $excludedActIds Optional moderation-hide list (per §K1 Phase C). Same coupling-avoidance pattern: caller decides which act_ids to drop, bcc-core just applies the filter.
     * @param int|null $onlyForGroupId Optional group-scope filter — when non-null and positive, restricts the candidate set to activities whose backing wp_post carries `peepso_group_id` post-meta matching this group. Used by /bcc/v1/groups/{id}/feed; bcc-trust enforces the privacy gate before invoking. The scope param is overridden internally to SCOPE_GROUP semantics (no follower / module filtering — every activity inside the group is in scope).
     * @param list<int>|null $excludedGroupIds Optional group-exclusion list — drop activities whose backing wp_post lives inside one of these PeepSo groups. Used by /bcc/v1/feed and /bcc/v1/feed/hot to suppress closed/secret/NFT-gated group posts from non-members. Same coupling-avoidance pattern as the other exclusion params: bcc-trust computes the list (non-open groups minus viewer memberships) and passes it through; bcc-core stays unaware of the privacy semantics. Ignored when `$onlyForGroupId` is set.
     * @param list<string>|null $groupVisibilityIn Optional per-post visibility allow-list for the GROUP-SCOPED path (`$onlyForGroupId` set). null = no visibility filter (member read → every post incl members_only). Non-null = restrict to posts whose `_bcc_post_visibility` post-meta is one of these values (bcc-trust passes ['public_group','public_all'] for a non-member teaser). Enforced as an INNER JOIN downstream so posts with absent visibility meta are EXCLUDED for non-members — the security invariant. Only applies on the `$onlyForGroupId` path; ignored for the global feed (which has its own per-post visibility gate keyed on 'public_all').
     * @param ?string $hashtag Optional hashtag filter (tag text WITHOUT the leading '#'). When a non-empty string, narrows the candidate set to posts whose body carries the '#tag' token (forwarded verbatim to PeepSoActivityRepository::getActivities, which applies the LIKE predicate). Pure narrowing — composes with every exclusion / visibility gate above; it can never surface a post those gates would otherwise drop. Used by the §F3 tag feed (`GET /bcc/v1/feed/tag`).
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getFeed(int $viewerId, string $scope, ?string $cursor = null, int $limit = 20, ?array $excludedAuthorIds = null, ?array $excludedActIds = null, ?int $onlyForGroupId = null, ?array $excludedGroupIds = null, ?array $groupVisibilityIn = null, ?string $hashtag = null): array
    {
        if (!in_array($scope, self::VALID_SCOPES, true)) {
            $scope = self::SCOPE_FOR_YOU;
        }
        $limit = max(1, min(50, $limit));

        [$cursorTime, $cursorActId] = self::decodeCursor($cursor);

        // Group-scoped feed: skip follower / module narrowing — every
        // activity inside the group is in scope (chronological ordering).
        // Author exclusions (§O4.1 caution / §K1 mutual block) and
        // act_id exclusions (§K1 Phase C moderation hide) still apply.
        if ($onlyForGroupId !== null && $onlyForGroupId > 0) {
            $authorIds = null;
            $moduleIds = null;
        } else {
            $authorIds = $this->resolveAuthorFilter($viewerId, $scope);
            $moduleIds = self::resolveModuleFilter($scope);
        }

        // Over-fetch by 1 to detect has_more without a separate count query.
        $rows = PeepSoActivityRepository::getActivities(
            $authorIds,
            $moduleIds,
            $cursorTime,
            $cursorActId,
            $limit + 1,
            $excludedAuthorIds,
            $excludedActIds,
            $onlyForGroupId,
            $excludedGroupIds,
            $groupVisibilityIn,
            $hashtag
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = $this->hydrateRows($rows, $viewerId);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last        = $rows[count($rows) - 1];
            $nextCursor  = self::encodeCursor((string) $last->act_time, (int) $last->act_id);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ];
    }

    /**
     * Per-author wall — the stream of activities authored by a single user.
     *
     * Backs the §3.1 "Activity" tab on /u/:handle. Same cursor-paginated
     * shape and same hydration as `getFeed()`, but filtered to one author
     * and unscoped (every module the user posted into shows up).
     *
     * Module filter is intentionally null — a user's wall surfaces every
     * kind they have authored (status, review, blog, pull_batch,
     * page_claim, signal, …). Caller can drop kinds out via the normal
     * `excludedActIds` channel if a per-act suppression is needed.
     *
     * `$viewerId` is the request viewer (0 = anonymous), used for author
     * hydration (follow-graph badges, etc.) — not the wall owner. Pass
     * the wall owner's id as `$authorId`.
     *
     * @param list<int>|null $excludedAuthorIds Same §O4.1 shadow-limit channel as `getFeed()`. On a single-author wall, passing the caution-tier list here makes the wall empty when the wall owner is on it — bcc-trust decides whether to apply that policy.
     * @param list<int>|null $excludedActIds    §K1 Phase C per-row moderation hide list.
     * @param list<int>|null $excludedGroupIds  Group-exclusion list — drop wall posts authored inside a closed/secret/NFT-gated group the viewer doesn't belong to. The wall is the same data stream as `getFeed()`, so the same leak surface applies; the same exclude-list pattern closes it.
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getActivityForAuthor(
        int $authorId,
        int $viewerId,
        ?string $cursor = null,
        int $limit = 20,
        ?array $excludedAuthorIds = null,
        ?array $excludedActIds = null,
        ?array $excludedGroupIds = null
    ): array {
        if ($authorId <= 0) {
            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }

        $limit = max(1, min(50, $limit));
        [$cursorTime, $cursorActId] = self::decodeCursor($cursor);

        // Over-fetch by 1 to detect has_more without a separate count.
        $rows = PeepSoActivityRepository::getActivities(
            [$authorId],
            null,
            $cursorTime,
            $cursorActId,
            $limit + 1,
            $excludedAuthorIds,
            $excludedActIds,
            null,
            $excludedGroupIds
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = $this->hydrateRows($rows, $viewerId);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last       = $rows[count($rows) - 1];
            $nextCursor = self::encodeCursor((string) $last->act_time, (int) $last->act_id);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ];
    }

    /**
     * Single-activity fetch for permalink reads (`GET /bcc/v1/feed/{id}`).
     * Same hydration as the list path (`hydrateRows`) so a permalink and
     * a feed card normalize identically; unpublished rows (trashed/draft)
     * are excluded the same way the list query's `post_status = 'publish'`
     * filter excludes them — `getById` itself has no such filter, so it's
     * enforced here instead.
     *
     * @return array<string, mixed>|null
     */
    public function getActivityById(int $actId, int $viewerId): ?array
    {
        if ($actId <= 0) {
            return null;
        }

        $row = PeepSoActivityRepository::getById($actId);
        if ($row === null || (string) $row->act_status !== 'publish') {
            return null;
        }

        $items = $this->hydrateRows([$row], $viewerId);
        return $items[0] ?? null;
    }

    /**
     * @return list<int>|null  null = no author filter; [] = no posts; non-empty = whitelist.
     */
    private function resolveAuthorFilter(int $viewerId, string $scope): ?array
    {
        if ($scope !== self::SCOPE_FOLLOWING) {
            return null;
        }

        if ($viewerId <= 0) {
            // Anonymous on `following` is a contract violation but we degrade
            // gracefully here rather than throwing — the controller layer is
            // responsible for the auth check.
            return [];
        }

        $following = $this->graph->getFollowing($viewerId);
        return $following === [] ? [] : array_values(array_unique(array_map('intval', $following)));
    }

    /**
     * @return list<string>|null  null = all modules; non-empty = whitelist.
     */
    private static function resolveModuleFilter(string $scope): ?array
    {
        if ($scope === self::SCOPE_SIGNALS) {
            return ['signal'];
        }
        return null;
    }

    /**
     * Per-row hydration. Kept thin so phase-2 ranking/social-proof composers
     * can wrap this service without re-implementing the loop.
     *
     * @param list<ActivityRow> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(array $rows, int $viewerId): array
    {
        if ($rows === []) {
            return [];
        }

        $authorIds = array_values(array_unique(array_map(static fn($r) => (int) $r->act_user_id, $rows)));
        $authors   = $this->hydrateAuthors($authorIds, $viewerId);

        $items = [];
        foreach ($rows as $row) {
            $authorId = (int) $row->act_user_id;
            $author   = $authors[$authorId] ?? self::fallbackAuthor($authorId);

            $items[] = FeedItemNormalizer::normalize(
                $row,
                $author,
                self::resolveBody($row),
                null, // reactions  — wired in Phase 2
                null, // socialProof — wired in Phase 2
                null, // attachedCard — wired in Phase 2
                []    // permissions — wired in Phase 2 via FeatureAccessService
            );
        }
        return $items;
    }

    /**
     * Batch author hydration. Pulled out so Phase 2 (trust score + card_tier
     * + rank_label) can extend without touching the loop.
     *
     * @param list<int> $authorIds
     * @return array<int, array<string, mixed>>
     */
    private function hydrateAuthors(array $authorIds, int $viewerId): array
    {
        if ($authorIds === []) {
            return [];
        }

        $followedSet = $viewerId > 0
            ? $this->graph->isFollowingBulk($viewerId, $authorIds)
            : [];

        $users = get_users([
            'include' => $authorIds,
            'fields'  => ['ID', 'display_name', 'user_login'],
            'number'  => count($authorIds),
        ]);

        $byId = [];
        foreach ($users as $u) {
            $id            = (int) $u->ID;
            $byId[$id] = [
                'kind'                  => 'user',
                'id'                    => $id,
                'handle'                => (string) get_user_meta($id, 'bcc_handle', true) ?: $u->user_login,
                'display_name'          => $u->display_name ?: $u->user_login,
                'avatar_url'            => self::resolveAvatarUrl($id),
                // Trust-derived fields are placeholders until Phase 2 wires
                // bcc-trust read services into this hydration step.
                'card_tier'             => null,
                'rank_label'            => null,
                'is_in_good_standing'   => true,
                'is_followed_by_viewer' => $followedSet[$id] ?? false,
            ];
        }
        return $byId;
    }

    /**
     * Body dispatcher. Maps each post_kind to its canonical body shape
     * by reading from the underlying source-of-truth (wp_posts for
     * native modules, BCC sidecar tables for BCC-owned modules — the
     * latter are hydrated by FeedRankingService::hydrateBodies after
     * this stub fires, so we leave them empty here).
     *
     * `act_module_id` arrives as either a numeric string (PeepSo native
     * modules: '1' for status/PeepSoActivity::MODULE_ID) or a kind
     * string (BCC modules: 'review', 'pull_batch', 'page_claim',
     * 'blog'). We accept both shapes for the status branch so a typo
     * in the writer side doesn't silently strip the post body.
     *
     * @param ActivityRow $row
     * @return array<string, mixed>
     */
    private static function resolveBody(object $row): array
    {
        $module = (string) $row->act_module_id;

        // Status posts: body lives in wp_posts.post_content. PeepSo
        // writes act_module_id as the integer 1; legacy/test code may
        // also use the string 'status'. Accept both.
        $isStatusModule = $module === ''
            || $module === 'status'
            || (class_exists('PeepSoActivity') && (int) $module === \PeepSoActivity::MODULE_ID);

        if ($isStatusModule) {
            $postId = (int) $row->act_external_id;
            $text   = '';
            if ($postId > 0) {
                $post = get_post($postId);
                if ($post instanceof \WP_Post) {
                    // post_content holds the user's text. PeepSo
                    // htmlspecialchars'd it on write — strip back to
                    // plain UTF-8 for the feed-item body. The frontend
                    // is responsible for any rendering treatment.
                    $text = html_entity_decode(
                        (string) $post->post_content,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                }
            }
            return [
                'text'   => $text,
                'embeds' => [],
            ];
        }

        if ($module === 'blog') {
            $postId = (int) $row->act_external_id;
            if ($postId <= 0) {
                return ['excerpt' => ''];
            }
            $post = get_post($postId);
            if (!$post instanceof \WP_Post) {
                return ['excerpt' => ''];
            }
            $handleRaw = get_user_meta((int) $post->post_author, 'bcc_handle', true);
            $handle = is_string($handleRaw) ? $handleRaw : '';
            return [
                'excerpt'       => (string) $post->post_excerpt,
                // Floor context — full_text is intentionally null so the
                // FeedItemCard knows to show a "Read full post" affordance
                // pointing at the per-user blog tab.
                'full_text'     => null,
                'author_handle' => $handle,
                'wp_post_id'    => $postId,
            ];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function fallbackAuthor(int $authorId): array
    {
        return [
            'kind'                  => 'user',
            'id'                    => $authorId,
            'handle'                => '',
            'display_name'          => '',
            'avatar_url'            => '',
            'card_tier'             => null,
            'rank_label'            => null,
            'is_in_good_standing'   => true,
            'is_followed_by_viewer' => false,
        ];
    }

    private static function resolveAvatarUrl(int $userId): string
    {
        // PeepSo stores avatars at a known path; get_avatar_url is the safe
        // cross-plugin abstraction. Always returns absolute URL per §1.7.
        $url = get_avatar_url($userId);
        return is_string($url) ? $url : '';
    }

    /**
     * Cursor format (locked, §1.5): base64url-encoded JSON of
     *   {"t": "<iso8601>", "id": <act_id>}
     *
     * Note: the contract also specifies an optional rs (rank_score_at_emit)
     * field for ranked feeds. Phase 1 chronological feeds omit it; the
     * decoder ignores unknown keys so adding it later is non-breaking.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return [null, null];
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['t'], $data['id'])) {
            return [null, null];
        }

        $iso = (string) $data['t'];
        $ts  = strtotime($iso);
        if ($ts === false) {
            return [null, null];
        }

        return [gmdate('Y-m-d H:i:s', $ts), (int) $data['id']];
    }

    private static function encodeCursor(string $mysqlDatetime, int $actId): string
    {
        $ts  = strtotime($mysqlDatetime . ' UTC');
        $iso = $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';

        $payload = json_encode(['t' => $iso, 'id' => $actId], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }
}
