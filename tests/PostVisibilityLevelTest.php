<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\VisibilityLevel;

use BCC\Core\Feed\PostVisibilityLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §F-02 effective post level, the PHP half. The SQL half, and the proof
 * that the two agree value by value, is PostVisibilityLevelSqlAgreementTest.
 */
#[CoversClass(PostVisibilityLevel::class)]
final class PostVisibilityLevelTest extends TestCase
{
    /**
     * These are PeepSo's own numbers, not ours to renumber: the columns
     * store them, and PeepSo's surfaces read them. 30 lives in
     * peepso-friends (ACCESS_FRIENDS), the rest in PeepSo core.
     */
    public function testTheRungsArePeepSosAccessValues(): void
    {
        self::assertSame(10, PostVisibilityLevel::PUBLIC);
        self::assertSame(20, PostVisibilityLevel::MEMBERS);
        self::assertSame(30, PostVisibilityLevel::FRIENDS);
        self::assertSame(40, PostVisibilityLevel::ONLY_ME);
    }

    /**
     * @return iterable<string, array{int|null, int}>
     */
    public static function storedValues(): iterable
    {
        yield 'NULL is public' => [null, 10];
        yield 'ACCESS_FORCE_PUBLIC (-1) is public' => [-1, 10];
        yield '0 (BCC\'s own writer) is public' => [0, 10];
        yield '1 is public' => [1, 10];
        yield '10 stays public' => [10, 10];
        yield '11 snaps up to members' => [11, 20];
        yield '15 snaps up to members' => [15, 20];
        yield '20 stays members' => [20, 20];
        yield '21 snaps up to friends' => [21, 30];
        yield '25 snaps up to friends' => [25, 30];
        yield '30 stays friends' => [30, 30];
        yield '31 snaps up to only-me' => [31, 40];
        yield '35 snaps up to only-me' => [35, 40];
        yield '40 stays only-me' => [40, 40];
        yield '255 (act_access ceiling) is only-me' => [255, 40];
    }

    /**
     * A stray stored value lands on the nearest STRICTER rung: never on
     * "invisible", and never on anything wider than it asked for.
     */
    #[DataProvider('storedValues')]
    public function testAStoredValueSnapsOntoTheLadderByRange(?int $stored, int $rung): void
    {
        self::assertSame($rung, PostVisibilityLevel::rung($stored));
    }

    /**
     * @return iterable<string, array{int|null, int|null, int}>
     */
    public static function postAndAuthor(): iterable
    {
        yield 'public post, public author' => [10, 10, 10];
        yield 'public post, members-only author' => [10, 20, 20];
        yield 'public post, friends-only author' => [10, 30, 30];
        yield 'public post, only-me author' => [10, 40, 40];
        yield 'members post, public author' => [20, 10, 20];
        yield 'friends post, members author' => [30, 20, 30];
        yield 'only-me post, public author' => [40, 10, 40];
        yield 'BCC writer\'s 0 post, members author' => [0, 20, 20];
        yield 'stray 15 post, public author' => [15, 10, 20];
        yield 'public post, stray 25 author' => [10, 25, 30];
        yield 'stray 15 post, stray 25 author' => [15, 25, 30];
        yield 'stray 35 post, public author' => [35, 10, 40];
        yield 'author at ACCESS_FORCE_PUBLIC does not widen a friends post' => [30, -1, 30];
        yield 'author at 0 does not widen a members post' => [20, 0, 20];
    }

    /**
     * The STRICTER of the two wins, in both directions. A profile
     * setting narrows the posts written under it; it never widens one.
     */
    #[DataProvider('postAndAuthor')]
    public function testTheEffectiveLevelIsTheStricterOfPostAndAuthor(?int $post, ?int $author, int $effective): void
    {
        self::assertSame($effective, PostVisibilityLevel::effective($post, $author));
    }

    /**
     * An author with NO `peepso_users` row is public, the decision the SQL
     * clause, the column default and PeepSo's own lazily created row all
     * share. So the post's own level decides.
     */
    public function testAnAuthorWithNoProfileRowLeavesThePostsOwnLevel(): void
    {
        self::assertSame(10, PostVisibilityLevel::effective(10, null));
        self::assertSame(20, PostVisibilityLevel::effective(20, null));
        self::assertSame(30, PostVisibilityLevel::effective(25, null));
        self::assertSame(40, PostVisibilityLevel::effective(40, null));
    }

    /**
     * Control for the case above: a missing row reads as public, so it
     * must never pull a post DOWN to public either.
     */
    public function testNullOnBothSidesIsPublic(): void
    {
        self::assertSame(10, PostVisibilityLevel::effective(null, null));
    }

    /** Every answer is one of the four rungs, whatever was stored. */
    public function testTheResultIsAlwaysARung(): void
    {
        $rungs = [10, 20, 30, 40];
        foreach ([null, -128, -1, 0, 5, 10, 12, 19, 20, 22, 29, 30, 33, 40, 41, 127, 255] as $post) {
            foreach ([null, -128, -1, 0, 10, 17, 20, 26, 30, 39, 40, 127] as $author) {
                self::assertContains(PostVisibilityLevel::effective($post, $author), $rungs);
            }
        }
    }
}
