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

    /**
     * No reactions apply to this post_kind at all. Empty kind set.
     *
     * ⚠ NOT the same as {@see GRAMMAR_TRIBAL}, which also exposes no kinds
     * today. `tribal` is documented in api-contract-v1.md §2.11 and
     * glossary.md as RESERVED FOR V2, with planned kinds (`same_wallet`,
     * `onchain_confirm`) and an identity-forward visual grammar — a
     * placeholder for a grammar that WILL carry kinds. Borrowing it to mean
     * "never gets reactions" would have to be reclaimed in V2 and would read
     * as "identity-forward reactions are coming" on content that is never
     * getting any. The two render identically today, which is exactly how
     * they would get conflated, so the distinction is stated here and in
     * both documents.
     *
     * ⚠⚠ THIS VALUE DOES NOT REMOVE A REACTION CONTROL FROM THE CLIENT, and
     * must not be relied on to. The per-kind reaction rail was replaced by a
     * single Stoke control that reads `heat_stage`/`viewer_has_stoked`/
     * `stoke_count` and does not consult `kind_grammar` at all. What this
     * value does buy, with no additional code:
     *
     *   - `counts` comes back as an EMPTY object, so a client fallback that
     *     lights an affordance from "any non-zero legacy count" can never
     *     fire;
     *   - {@see kindBelongsToGrammar} returns false for EVERY kind, and the
     *     reactions endpoint validates set-reaction requests with exactly
     *     that call — so the reactions WRITE path fails closed;
     *   - {@see \BCC\Trust\Core\Support\ReactionStateComposer} iterates
     *     `kindsFor()`, so it performs zero lookups.
     *
     * Suppressing the rendered affordance is a separate change in the
     * consuming client. See api-contract-v1.md §4.32.15.
     *
     * @since v1.78 (announcements — an official notice takes no reactions)
     */
    public const GRAMMAR_NONE = 'none';

    /**
     * Trust-grammar reaction kinds. `solid` is the ONLY trust reaction.
     *
     * `vouch` and `stand_behind` are NOT reactions — they belong to the
     * separate person/entity attestation system (`bcc_trust_attestations`;
     * the byline "Vouch" toggle + §J profile actions), which moves trust
     * scores. `stand_behind` was retired as a reaction in Slice 3; `vouch`
     * was relocated to attestations. Do not re-add them here — a reaction
     * named `vouch` would shadow the live attestation of the same name.
     */
    public const KIND_SOLID        = 'solid';

    /** Social-grammar kinds — v1.5 curated subset (👍 ❤️ 😂 😮 🔥). */
    public const KIND_LIKE = 'like';
    public const KIND_LOVE = 'love';
    public const KIND_HAHA = 'haha';
    public const KIND_WOW  = 'wow';
    public const KIND_FIRE = 'fire';

    /** @var list<string> */
    public const TRUST_KINDS = [
        self::KIND_SOLID,
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

        // No grammar — an official validator notice takes no reactions at
        // all. Registered EXPLICITLY rather than left to the `?? social`
        // default below, which would zero-fill {like,love,haha,wow,fire}
        // onto an operator's announcement. See GRAMMAR_NONE.
        'announcement'    => self::GRAMMAR_NONE,
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
            // Listed rather than left to `default`, so the empty set is a
            // declared property of this grammar and not an artefact of an
            // unrecognised value. A reader checking "does `none` really
            // expose nothing" finds the answer here.
            self::GRAMMAR_NONE   => [],
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
     * `counts` as it must appear ON THE WIRE.
     *
     * ⚠⚠ AN EMPTY COUNTS DICT MUST SERIALIZE AS `{}`, NOT `[]`, and PHP
     * will not do that on its own: `json_encode([])` emits a JSON ARRAY.
     * Measured before this existed — the no-reactions block went out as
     * `{"kind_grammar":"none","counts":[],"viewer_reaction":null}` while
     * api-contract-v1.md §2.11 and §4.32.15 both say an empty object.
     *
     * It is not a cosmetic difference. `counts` is an object for every
     * other grammar, so emitting an array for exactly one kind changes
     * the field's TYPE on the wire — a typed client declaring
     * `Record<string, number>`, or any schema validator, is entitled to
     * reject it, and a consumer that happens to tolerate it does so by
     * luck rather than by contract.
     *
     * Applied at the SERIALIZATION BOUNDARY, not inside
     * {@see emptyCountsFor}, because hydration mutates the dict by key
     * (`array_key_exists` / `$counts[$kind] = …`) and an object would
     * break that. Callers build an array, then hand it through here.
     *
     * @param  array<string, int> $counts
     * @return array<string, int>|\stdClass
     */
    public static function countsForWire(array $counts)
    {
        return $counts === [] ? new \stdClass() : $counts;
    }

    /**
     * Empty reactions block for the given post_kind — derives the
     * grammar and emits the contract-shape `{kind_grammar, counts,
     * viewer_reaction}` block. The normalizer calls this when no
     * reactions hydrator has run.
     *
     * `counts` goes through {@see countsForWire} because this is a
     * serialization boundary: under the `none` grammar the dict is empty,
     * and an empty PHP array would encode as `[]` rather than the `{}`
     * the contract specifies.
     *
     * @return array{kind_grammar: string, counts: array<string, int>|\stdClass, viewer_reaction: null}
     */
    public static function emptyReactionsFor(string $postKind): array
    {
        $grammar = self::grammarFor($postKind);
        return [
            'kind_grammar'    => $grammar,
            'counts'          => self::countsForWire(self::emptyCountsFor($grammar)),
            'viewer_reaction' => null,
        ];
    }
}
