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
 *   pull_batch   -> pull_batch    (bcc emits via §C3 batch close; act_external_id -> bcc_pull_batches.id)
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
    /** @var array<string, string> peepso act_module_id => contract post_kind */
    private const MODULE_TO_KIND = [
        'status'     => 'status',
        'review'     => 'review',
        'pull_batch' => 'pull_batch',
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
     * @param array{counts: array{solid: int, vouch: int, stand_behind: int}, viewer_reaction: ?string}|null $reactions
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
            // Module-specific FK (e.g. bcc_pull_batches.id for pull_batch,
            // bcc_onchain_claims.id for page_claim, wp_posts.ID for status,
            // 0 for system events). Used by hydrators (server-side) and
            // for stable client-side react keys; the frontend treats it
            // as opaque.
            'external_id' => (int) $row->act_external_id,
            'posted_at'  => self::toIso8601((string) ($row->act_time ?? '')),
            'scope_tags' => self::deriveScopeTags($postKind, (string) ($row->act_access ?? '')),
            'author'     => $author,
            'body'       => $body,
            'reactions'  => $reactions ?? self::emptyReactions(),
            'permissions' => $permissions !== [] ? $permissions : self::defaultPermissions(),
            'links' => [
                'self'   => '/p/feed_' . (int) $row->act_id,
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

    /** @return array{counts: array{solid: int, vouch: int, stand_behind: int}, viewer_reaction: null} */
    private static function emptyReactions(): array
    {
        return [
            'counts'          => ['solid' => 0, 'vouch' => 0, 'stand_behind' => 0],
            'viewer_reaction' => null,
        ];
    }

    /** @return array<string, array{allowed: bool, unlock_hint: ?string}> */
    private static function defaultPermissions(): array
    {
        // Conservative default — service should override per viewer.
        return [
            'can_react'  => ['allowed' => false, 'unlock_hint' => null],
            'can_reply'  => ['allowed' => false, 'unlock_hint' => null],
            'can_share'  => ['allowed' => true,  'unlock_hint' => null],
            'can_report' => ['allowed' => false, 'unlock_hint' => null],
        ];
    }
}
