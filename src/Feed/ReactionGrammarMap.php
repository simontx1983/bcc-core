<?php
/**
 * ReactionGrammarMap — contract layer for the v1.5 layered reaction
 * model. Maps post_kind to its interaction grammar and lists which
 * reaction kinds each grammar exposes. Pure data — no DB, no option
 * reads, no ID resolution.
 *
 * Lives in bcc-core because the FeedItem view-model contract
 * (api-contract-v1.md §2.11) lives here. The shape that gets emitted
 * to the wire is determined here; ID resolution (which post.ID does
 * "solid" map to?) lives in bcc-trust where the seeded reaction posts
 * are owned (BCC\Trust\Core\Support\ReactionGrammarRegistry).
 *
 * Three grammars:
 *   - trust  — restrained, intentional. Solid / Vouch.
 *              Reputation-bearing post_kinds.
 *   - social — expressive, emoji-forward. Like / Love / Haha / Wow / Fire.
 *              Culture-formation post_kinds.
 *   - tribal — reserved for V2 (same_wallet, onchain_confirm, etc.).
 *              Currently no kinds; the discriminator exists so the
 *              frontend rail's grammar-branch is forward-compatible.
 *
 * Unmapped post_kinds default to 'social' — see POST_KIND_TO_GRAMMAR
 * for the rationale.
 *
 * @package BCC\Core\Feed
 * @since v1.5 (2026-05, social grammar)
 */

namespace BCC\Core\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionGrammarMap
{
    public const GRAMMAR_TRUST  = 'trust';
    public const GRAMMAR_SOCIAL = 'social';
    public const GRAMMAR_TRIBAL = 'tribal';

    /** Trust-grammar kinds (stand_behind retired in Slice 3). */
    public const KIND_SOLID        = 'solid';
    public const KIND_VOUCH        = 'vouch';

    /** Social-grammar kinds — v1.5 curated subset (👍 ❤️ 😂 😮 🔥). */
    public const KIND_LIKE = 'like';
    public const KIND_LOVE = 'love';
    public const KIND_HAHA = 'haha';
    public const KIND_WOW  = 'wow';
    public const KIND_FIRE = 'fire';

    /** @var list<string> */
    public const TRUST_KINDS = [
        self::KIND_SOLID,
        self::KIND_VOUCH,
    ];

    /** @var list<string> */
    public const SOCIAL_KINDS = [
        self::KIND_LIKE,
        self::KIND_LOVE,
        self::KIND_HAHA,
        self::KIND_WOW,
        self::KIND_FIRE,
    ];

    /**
     * post_kind → grammar map. Keep in sync with
     * docs/api-contract-v1.md §2.11.
     *
     * Unmapped post_kinds default to 'social' (warmer grammar). A new
     * kind that should carry trust gravity MUST be explicitly added
     * here — defaulting to trust would risk surfacing emoji on
     * reputation-bearing posts the day someone forgets to register a
     * new social kind, which dilutes the trust grammar far worse than
     * the inverse error.
     *
     * @var array<string, string>
     */
    private const POST_KIND_TO_GRAMMAR = [
        // Trust grammar — reputation-bearing kinds.
        'review'          => self::GRAMMAR_TRUST,
        'dispute_signed'  => self::GRAMMAR_TRUST,
        'page_claim'      => self::GRAMMAR_TRUST,
        'project_drop'    => self::GRAMMAR_TRUST,
        'nft_drop'        => self::GRAMMAR_TRUST,
        'signal'          => self::GRAMMAR_TRUST,

        // Social grammar — culture-formation kinds.
        'status'          => self::GRAMMAR_SOCIAL,
        'watch_batch'     => self::GRAMMAR_SOCIAL,
        // Tolerated legacy key: pre-rename activity rows whose post_kind
        // resolves to 'pull_batch' still grade with the social grammar.
        'pull_batch'      => self::GRAMMAR_SOCIAL,
        'blog_excerpt'    => self::GRAMMAR_SOCIAL,
    ];

    /**
     * Grammar that applies to the given post_kind. Returns "social"
     * for unmapped kinds.
     */
    public static function grammarFor(string $postKind): string
    {
        return self::POST_KIND_TO_GRAMMAR[$postKind] ?? self::GRAMMAR_SOCIAL;
    }

    /**
     * Grammar that applies to the given act_module_id (the column
     * stored on peepso_activities rows). Reads
     * FeedItemNormalizer::MODULE_TO_KIND so the module → post_kind
     * translation has one source of truth. Returns "social" for
     * unknown modules (matches FeedItemNormalizer's fallback to
     * post_kind 'status', which itself is social).
     */
    public static function grammarForModule(string $moduleId): string
    {
        $postKind = FeedItemNormalizer::MODULE_TO_KIND[$moduleId] ?? 'status';
        return self::grammarFor($postKind);
    }

    /**
     * The reaction kinds a given grammar exposes, in render order.
     * The frontend rail renders these kinds in this exact sequence.
     *
     * @return list<string>
     */
    public static function kindsFor(string $grammar): array
    {
        return match ($grammar) {
            self::GRAMMAR_TRUST  => self::TRUST_KINDS,
            self::GRAMMAR_SOCIAL => self::SOCIAL_KINDS,
            self::GRAMMAR_TRIBAL => [],
            default              => [],
        };
    }

    /**
     * True when the given kind belongs to the given grammar. Used by
     * the reactions endpoint to reject cross-grammar set-reaction
     * requests.
     */
    public static function kindBelongsToGrammar(string $kind, string $grammar): bool
    {
        return in_array($kind, self::kindsFor($grammar), true);
    }

    /**
     * Every reaction kind this map knows about, across grammars.
     * Used by the reactions endpoint as the request-validation enum.
     *
     * @return list<string>
     */
    public static function allKnownKinds(): array
    {
        return array_merge(self::TRUST_KINDS, self::SOCIAL_KINDS);
    }

    /**
     * Empty (zero-filled) counts dict for the given grammar. Every
     * kind in the grammar is present at 0. Used by the normalizer's
     * fallback when no reactions hydrator has run.
     *
     * @return array<string, int>
     */
    public static function emptyCountsFor(string $grammar): array
    {
        $counts = [];
        foreach (self::kindsFor($grammar) as $kind) {
            $counts[$kind] = 0;
        }
        return $counts;
    }

    /**
     * Empty reactions block for the given post_kind — derives the
     * grammar and emits the contract-shape `{kind_grammar, counts,
     * viewer_reaction}` block. The normalizer calls this when no
     * reactions hydrator has run.
     *
     * @return array{kind_grammar: string, counts: array<string, int>, viewer_reaction: null}
     */
    public static function emptyReactionsFor(string $postKind): array
    {
        $grammar = self::grammarFor($postKind);
        return [
            'kind_grammar'    => $grammar,
            'counts'          => self::emptyCountsFor($grammar),
            'viewer_reaction' => null,
        ];
    }
}
