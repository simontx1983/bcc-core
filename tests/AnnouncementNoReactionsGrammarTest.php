<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\AnnouncementNoReactions;

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Feed\ReactionGrammarMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Announcements take no reactions — the bcc-core half.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. THE ANNOUNCEMENT KIND FALLS THROUGH TO THE SOCIAL DEFAULT.
 *     `grammarFor()` ends in `?? GRAMMAR_SOCIAL`, so an UNREGISTERED kind
 *     does not fail — it silently zero-fills {like, love, haha, wow, fire}
 *     onto an operator's official notice. The registration is the whole
 *     fix, and a missing map entry is invisible without this assertion.
 *  2. `none` IS TREATED AS INTERCHANGEABLE WITH `tribal`. Both expose no
 *     kinds today, so they are indistinguishable by behaviour alone —
 *     which is exactly how they would get conflated. `tribal` is reserved
 *     for a V2 grammar that WILL carry kinds; `none` means never.
 *  3. A REACTION CAN STILL BE WRITTEN. The reactions endpoint validates a
 *     set-reaction request with `kindBelongsToGrammar()`. An empty kind set
 *     must make that false for EVERY known kind, or the write path is open
 *     while the read path looks clean.
 *  4. THE CHANGE LEAKS INTO ANOTHER KIND. Every pre-existing post_kind must
 *     keep its exact grammar and its exact kind list. This is an additive
 *     change or it is a regression.
 *  5. `allKnownKinds()` SHRINKS. It is the reactions endpoint's
 *     request-validation enum. If adding a grammar changed it, every other
 *     kind's accepted request set would move.
 *
 * ⚠ What this file does NOT prove, and cannot: that no reaction CONTROL is
 * rendered. The per-kind reaction rail was replaced by a single Stoke
 * control which reads `heat_stage`/`viewer_has_stoked`/`stoke_count` and
 * never consults `kind_grammar`. Suppression is a consumer-side change
 * (api-contract-v1.md §4.32.15, layer 3). This file pins the vocabulary and
 * the write-path closure only.
 */
#[CoversClass(ReactionGrammarMap::class)]
#[CoversClass(FeedItemNormalizer::class)]
final class AnnouncementNoReactionsGrammarTest extends TestCase
{
    private const ANNOUNCEMENT = 'announcement';

    /** Every post_kind that existed before announcements, with its grammar. */
    private const PRE_EXISTING = [
        'review'         => ReactionGrammarMap::GRAMMAR_TRUST,
        'dispute_signed' => ReactionGrammarMap::GRAMMAR_TRUST,
        'page_claim'     => ReactionGrammarMap::GRAMMAR_TRUST,
        'project_drop'   => ReactionGrammarMap::GRAMMAR_TRUST,
        'nft_drop'       => ReactionGrammarMap::GRAMMAR_TRUST,
        'signal'         => ReactionGrammarMap::GRAMMAR_TRUST,
        'status'         => ReactionGrammarMap::GRAMMAR_SOCIAL,
        'watch_batch'    => ReactionGrammarMap::GRAMMAR_SOCIAL,
        'pull_batch'     => ReactionGrammarMap::GRAMMAR_SOCIAL,
        'blog_excerpt'   => ReactionGrammarMap::GRAMMAR_SOCIAL,
    ];

    // ── 1. The registration itself — MUST-NOT-HAPPEN #1 ───────────────────

    public function testTheAnnouncementKindResolvesToTheNoneGrammar(): void
    {
        self::assertSame(
            ReactionGrammarMap::GRAMMAR_NONE,
            ReactionGrammarMap::grammarFor(self::ANNOUNCEMENT)
        );
    }

    /**
     * The registration is load-bearing because the fallback is SOCIAL, not
     * an error. Proved by contrast: an invented kind takes the default, the
     * announcement kind does not — so the assertion above is about the map
     * entry and not about some blanket behaviour.
     */
    public function testAnUnregisteredKindStillFallsBackToSocialSoTheEntryIsWhatMatters(): void
    {
        self::assertSame(
            ReactionGrammarMap::GRAMMAR_SOCIAL,
            ReactionGrammarMap::grammarFor('a_kind_nobody_registered'),
            'the fallback is social, which is why announcement must be registered explicitly'
        );
        self::assertNotSame(
            ReactionGrammarMap::grammarFor('a_kind_nobody_registered'),
            ReactionGrammarMap::grammarFor(self::ANNOUNCEMENT)
        );
    }

    public function testTheAnnouncementModuleResolvesThroughTheModuleMapToo(): void
    {
        self::assertSame(
            self::ANNOUNCEMENT,
            FeedItemNormalizer::MODULE_TO_KIND['205'] ?? null
        );
        self::assertSame(
            ReactionGrammarMap::GRAMMAR_NONE,
            ReactionGrammarMap::grammarForModule('205'),
            'the module path and the kind path must agree'
        );
    }

    // ── 2. An empty kind set, and the empty wire shape ────────────────────

    public function testTheNoneGrammarExposesNoKinds(): void
    {
        self::assertSame([], ReactionGrammarMap::kindsFor(ReactionGrammarMap::GRAMMAR_NONE));
    }

    /**
     * `counts` must be an EMPTY object, not a zero-filled one. A consumer
     * fallback that lights an affordance from "any non-zero legacy count"
     * cannot fire against an empty set, whereas a zero-filled social dict
     * would at least give it something to read.
     */
    public function testTheEmptyReactionsBlockForAnAnnouncementCarriesNoCounts(): void
    {
        $block = ReactionGrammarMap::emptyReactionsFor(self::ANNOUNCEMENT);

        self::assertSame(ReactionGrammarMap::GRAMMAR_NONE, $block['kind_grammar']);
        self::assertSame([], $block['counts']);
        self::assertNull($block['viewer_reaction']);
    }

    public function testEmptyCountsForTheNoneGrammarIsEmpty(): void
    {
        self::assertSame([], ReactionGrammarMap::emptyCountsFor(ReactionGrammarMap::GRAMMAR_NONE));
    }

    /** Contrast, so the assertion above is about `none` and not about everything. */
    public function testASocialKindStillGetsItsFiveZeroFilledCounts(): void
    {
        $block = ReactionGrammarMap::emptyReactionsFor('status');

        self::assertSame(ReactionGrammarMap::GRAMMAR_SOCIAL, $block['kind_grammar']);
        self::assertSame(
            ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'fire' => 0],
            $block['counts']
        );
    }

    // ── 3. The write path fails closed — MUST-NOT-HAPPEN #3 ───────────────

    /**
     * `kindBelongsToGrammar()` is what the reactions endpoint uses to reject
     * a cross-grammar set-reaction request. Under `none` it must reject
     * EVERY kind the map knows, including the trust kind — so no reaction
     * can be written to an announcement without new code being added.
     */
    public function testNoKnownReactionKindBelongsToTheNoneGrammar(): void
    {
        $known = ReactionGrammarMap::allKnownKinds();
        self::assertNotSame([], $known, 'positive control: there are kinds to reject');

        foreach ($known as $kind) {
            self::assertFalse(
                ReactionGrammarMap::kindBelongsToGrammar($kind, ReactionGrammarMap::GRAMMAR_NONE),
                "{$kind} must not be settable under the none grammar"
            );
        }
    }

    public function testEvenAnInventedKindIsRejectedUnderTheNoneGrammar(): void
    {
        self::assertFalse(
            ReactionGrammarMap::kindBelongsToGrammar('whatever', ReactionGrammarMap::GRAMMAR_NONE)
        );
    }

    // ── 4. `none` is not `tribal` — MUST-NOT-HAPPEN #2 ────────────────────

    /**
     * They are behaviourally identical today, so only their identity
     * distinguishes them. `tribal` is documented in api-contract-v1.md
     * §2.11 and glossary.md as reserved for V2 with planned kinds
     * (`same_wallet`, `onchain_confirm`); `none` means this kind never gets
     * reactions. Pinned so a later "these both return [], merge them"
     * cleanup has to confront the distinction.
     */
    public function testNoneAndTribalAreDistinctValuesDespiteBehavingAlikeToday(): void
    {
        self::assertNotSame(ReactionGrammarMap::GRAMMAR_NONE, ReactionGrammarMap::GRAMMAR_TRIBAL);
        self::assertSame('none', ReactionGrammarMap::GRAMMAR_NONE);
        self::assertSame('tribal', ReactionGrammarMap::GRAMMAR_TRIBAL);

        // Same observable behaviour — which is the hazard, not a property to rely on.
        self::assertSame(
            ReactionGrammarMap::kindsFor(ReactionGrammarMap::GRAMMAR_TRIBAL),
            ReactionGrammarMap::kindsFor(ReactionGrammarMap::GRAMMAR_NONE)
        );
    }

    public function testNoPostKindIsMappedToTribal(): void
    {
        foreach (array_keys(self::PRE_EXISTING) as $kind) {
            self::assertNotSame(
                ReactionGrammarMap::GRAMMAR_TRIBAL,
                ReactionGrammarMap::grammarFor($kind),
                "tribal is reserved for V2 and must claim no kind yet ({$kind})"
            );
        }
        self::assertNotSame(
            ReactionGrammarMap::GRAMMAR_TRIBAL,
            ReactionGrammarMap::grammarFor(self::ANNOUNCEMENT)
        );
    }

    // ── 5. Nothing else moved — MUST-NOT-HAPPEN #4 and #5 ─────────────────

    public function testEveryPreExistingPostKindKeepsItsExactGrammar(): void
    {
        foreach (self::PRE_EXISTING as $kind => $grammar) {
            self::assertSame($grammar, ReactionGrammarMap::grammarFor($kind), $kind);
        }
    }

    public function testEveryPreExistingPostKindKeepsItsExactKindList(): void
    {
        self::assertSame(['solid'], ReactionGrammarMap::kindsFor(ReactionGrammarMap::GRAMMAR_TRUST));
        self::assertSame(
            ['like', 'love', 'haha', 'wow', 'fire'],
            ReactionGrammarMap::kindsFor(ReactionGrammarMap::GRAMMAR_SOCIAL)
        );
    }

    /**
     * `allKnownKinds()` is the reactions endpoint's request-validation enum.
     * Adding a grammar with no kinds must not change it, or every other
     * kind's accepted request set moves with it.
     */
    public function testTheKnownKindEnumIsUnchangedByTheNewGrammar(): void
    {
        self::assertSame(
            ['solid', 'like', 'love', 'haha', 'wow', 'fire'],
            ReactionGrammarMap::allKnownKinds()
        );
    }

    /**
     * The module map gained exactly one numeric entry. Asserted as the whole
     * set so an accidental extra module cannot slip in beside it.
     */
    public function testTheNumericModuleMapGainedOnlyTwoHundredAndFive(): void
    {
        $numeric = [];
        foreach (FeedItemNormalizer::MODULE_TO_KIND as $moduleId => $kind) {
            if (is_int($moduleId)) {
                $numeric[$moduleId] = $kind;
            }
        }

        self::assertSame(
            [
                1   => 'status',
                4   => 'photo',
                200 => 'watch_batch',
                201 => 'page_claim',
                202 => 'review',
                203 => 'dispute_signed',
                204 => 'blog_excerpt',
                205 => 'announcement',
            ],
            $numeric
        );
    }

    /**
     * Admitting 205 into the public feed query gate is safe ONLY because no
     * writer emits it yet. Recorded as an assertion about the gate rather
     * than a comment, so the claim is checkable.
     */
    public function testTwoHundredAndFiveIsAdmittedToThePublicFeedGate(): void
    {
        self::assertContains(205, FeedItemNormalizer::publicFeedModuleIds());
    }
}
