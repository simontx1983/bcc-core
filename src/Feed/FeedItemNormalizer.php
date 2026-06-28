<?php

namespace BCC\Core\Feed;

use BCC\Core\Repositories\PeepSoActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure transform: a peepso_activities row -> a FeedItem-shaped associative
 * array per the API contract §3.3.
 *
 * No DB access. No external services. The caller (ActivityFeedService) is
 * responsible for hydrating author identity, attached cards, reactions, and
 * social_proof BEFORE calling this — those hydrations are batched by the
 * service for N+1 avoidance.
 *
 * The output shape is intentionally minimal-but-stable: fields the contract
 * lists as required are always present (using sentinel values where the
 * caller hasn't supplied data yet); optional fields are omitted when absent.
 *
 * Mapping table — peepso act_module_id -> contract post_kind:
 *   status       -> status
 *   review       -> review        (bcc-trust review post; act_external_id -> bcc_trust_votes row)
 *   watch_batch  -> watch_batch   (bcc emits via §C3 batch close; act_external_id -> bcc_watch_batches.id).
 *                                  The legacy act_module_id string 'pull_batch' is still accepted and
 *                                  normalizes to the canonical 'watch_batch' (no data migration — mapped on read).
 *   page_claim   -> page_claim    (bcc emits on bcc_page_claimed; act_external_id -> bcc_onchain_claims.id)
 *   dispute      -> dispute_signed
 *   signal       -> signal        (bcc emits via on-chain refresh)
 *   project      -> project_drop
 *   nft          -> nft_drop
 *   blog         -> blog_excerpt  (D6 — same kind, two render contexts)
 *
 * Unknown modules pass through with post_kind = 'status' so they render as a
 * generic post; the frontend never sees an unknown post_kind.
 *
 * @phpstan-import-type ActivityRow from PeepSoActivityRepository
 */
final class FeedItemNormalizer
{
    /**
     * peepso act_module_id => contract post_kind. Public because
     * ReactionGrammarMap reads this to derive grammar from a
     * peepso_activities row when no normalized post_kind is at
     * hand (e.g. inside the reactions endpoint, given just the
     * act_id). Single source of truth for the module → post_kind
     * translation.
     *
     * --- Evolving infrastructure note (v1.5) ---
     *
     * This map is the seam between PeepSo's raw activity primitives
     * (numeric module IDs that stamp `act_module_id`) and BCC's
     * semantic post_kind contract. Future kinds (poll, drop,
     * celebration, dispute outcome, collection unlock, mint event)
     * will all flow through here. Keep entries explicit; do NOT rely
     * on the `?? 'status'` fallback as a feature.
     *
     * Integer-keyed entries (cast to string by the lookup) are the
     * actual numeric `act_module_id` values written to peepso_activities.
     * Two namespaces share this map:
     *
     *   • 1, 4, …  — PeepSo's native module IDs as PeepSo emits them.
     *   • 200+     — BCC-owned numeric IDs assigned by
     *                `PeepSoActivityWriter::MODULE_ID_BY_NAME`. The
     *                writer translates a string name ('blog', 'review')
     *                to its integer id before INSERT because the column
     *                is SMALLINT — string writes coerce to 0 and lose
     *                discrimination on the read side. The 200-range is
     *                deliberately outside PeepSo's known modules to
     *                guarantee no collision.
     *
     * String-keyed entries (`'review'`, `'watch_batch'`, etc.) below are
     * a historical record from before the 2026-05-15 SMALLINT fix —
     * they NEVER fired in practice (the broken writes coerced to 0),
     * so the lookup fell through to 'status'. Kept here so callers
     * passing string module names see a deterministic mapping; any
     * future writer that legitimately stamps strings can land safely.
     *
     * PeepSo's native module ID legend (as actually stored):
     *   1    = PeepSoActivity     (status)             — explicit since v1.5
     *   4    = PeepSoSharePhotos  (photo)              — added in v1.5
     *
     * Currently-unmapped PeepSo modules — known integers we deliberately
     * do NOT translate yet:
     *   6    = PeepSoMessages     — DMs; should never reach the feed
     *   9    = PeepSoPages        — page-wall posts; defer to V2 page surface
     *   30   = PeepSoPolls        — V2 candidate, real new post_kind
     *   111  = PeepSoPostBackgrounds — decorative variant of status
     *   6661 = BLOGPOSTS_MODULE_ID — peepso-blog post type, separate flow
     */
    public const MODULE_TO_KIND = [
        // Native PeepSo modules — integer keys (cast to string by the lookup).
        '1' => 'status',
        '4' => 'photo',

        // BCC-owned modules — integer keys (200-range). Must stay in
        // lockstep with PeepSoActivityWriter::MODULE_ID_BY_NAME.
        '200' => 'watch_batch',
        '201' => 'page_claim',
        '202' => 'review',
        '203' => 'dispute_signed',
        '204' => 'blog_excerpt',

        // BCC-owned modules — string keys (legacy fallback). The
        // pre-2026-05-15 writer wrote these as strings, but the
        // SMALLINT column coerced them to 0 — so no production row
        // ever matched these keys. Kept for callers that pass the
        // string name through {ReactionGrammarMap::deriveFromRow},
        // which lets the kind resolve uniformly regardless of how the
        // moduleId arrived.
        'status'     => 'status',
        'review'     => 'review',
        // Canonical key + the tolerated legacy 'pull_batch' alias. Both map
        // to the canonical 'watch_batch' post_kind so pre-existing
        // peepso_activities.act_module_id rows written before the rename
        // still normalize correctly (mapped on read; no data migration).
        'watch_batch' => 'watch_batch',
        'pull_batch'  => 'watch_batch',
        'page_claim' => 'page_claim',
        'dispute'    => 'dispute_signed',
        'signal'     => 'signal',
        'project'    => 'project_drop',
        'nft'        => 'nft_drop',
        'blog'       => 'blog_excerpt',
    ];

    /**
     * @param ActivityRow $row     A row from PeepSoActivityRepository::getActivities().
     * @param array<string, mixed> $author Pre-hydrated author block (kind/id/handle/display_name/avatar_url/card_tier/rank_label/is_in_good_standing/is_followed_by_viewer).
     * @param array<string, mixed> $body   Pre-hydrated kind-specific body (per §3.3.1–3.3.8). Empty = use defaults.
     * @param array{kind_grammar: string, counts: array<string, int>, viewer_reaction: ?string}|null $reactions
     * @param array<string, mixed>|null $socialProof  Pre-composed §2.2 SocialProof or null.
     * @param array<string, mixed>|null $attachedCard Pre-hydrated summary Card view-model or null.
     * @param array<string, array{allowed: bool, unlock_hint: ?string}> $permissions
     * @return array<string, mixed>
     */
    public static function normalize(
        object $row,
        array $author,
        array $body = [],
        ?array $reactions = null,
        ?array $socialProof = null,
        ?array $attachedCard = null,
        array $permissions = []
    ): array {
        $module   = (string) ($row->act_module_id ?? '');
        $postKind = self::MODULE_TO_KIND[$module] ?? 'status';

        $item = [
            'id'         => 'feed_' . (int) $row->act_id,
            'post_kind'  => $postKind,
            // Module-specific FK (e.g. bcc_watch_batches.id for watch_batch,
            // bcc_onchain_claims.id for page_claim, wp_posts.ID for status,
            // 0 for system events). Used by hydrators (server-side) and
            // for stable client-side react keys; the frontend treats it
            // as opaque.
            'external_id' => (int) $row->act_external_id,
            'posted_at'  => self::toIso8601((string) ($row->act_time ?? '')),
            'scope_tags' => self::deriveScopeTags($postKind, (string) ($row->act_access ?? '')),
            'author'     => $author,
            'body'       => $body,
            // Reactions are grammar-aware (api-contract-v1.md §2.11):
            // every block carries kind_grammar + counts keyed by the
            // grammar's kinds. ReactionGrammarMap derives grammar from
            // post_kind; the hydrator (FeedRankingService) populates
            // the counts when it runs. When no hydrator has run, the
            // empty fallback emits the grammar-correct zero shape.
            'reactions'  => $reactions ?? ReactionGrammarMap::emptyReactionsFor($postKind),
            'permissions' => $permissions !== [] ? $permissions : self::defaultPermissions(),
            'links' => [
                'self'   => '/post/' . (int) $row->act_id,
                'author' => isset($author['handle']) ? '/u/' . $author['handle'] : '',
            ],
        ];

        if ($socialProof !== null) {
            $item['social_proof'] = $socialProof;
        }

        if ($attachedCard !== null) {
            $item['attached_card'] = $attachedCard;
        }

        return $item;
    }

    /**
     * Pulls scope eligibility per §N6:
     *   for_you  — every non-system post is eligible.
     *   following — every non-system post (filtering to viewer's network is the service's job, not the normalizer's).
     *   signals  — only signal post-kinds.
     *
     * @return list<string>
     */
    private static function deriveScopeTags(string $postKind, string $access): array
    {
        if ($postKind === 'signal') {
            return ['for_you', 'signals'];
        }
        return ['for_you', 'following'];
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        // PeepSo stores act_time as MySQL DATETIME in UTC.
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    /** @return array<string, array{allowed: bool, unlock_hint: ?string}> */
    private static function defaultPermissions(): array
    {
        // can_report is the only real feed-action gate today —
        // FeedRankingService::hydrateViewerPermissions overrides it per
        // viewer (authed + not-the-author) and ReportButton reads it; the
        // conservative false here is the fallback for any normalize() path
        // that doesn't hydrate. can_react / can_reply / can_share were
        // removed (2026-06-11): never overridden per viewer, never read by
        // the frontend, share has no feature, and react/reply shipped
        // `false` while both actually work — a documented-but-unbacked
        // trap. Re-add a gate here only when the action is real AND a
        // consumer reads it.
        return [
            'can_report' => ['allowed' => false, 'unlock_hint' => null],
        ];
    }
}
