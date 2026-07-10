<?php

namespace BCC\Core\PeepSo;

use BCC\Core\Repositories\PeepSoFollowerRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single read-side access point for the PeepSo follower graph.
 *
 * Per the locked plan: "one graph". Anything in BCC that needs to know who
 * follows whom — feed scoping, social proof composition, mutual lookups,
 * pull-eligibility checks for member cards — calls this service. No other
 * code reads peepso_user_followers directly.
 *
 * Read-only. Writes go through PeepSo's own follow/unfollow flow.
 *
 * Per-request memoization avoids N+1 lookups when a feed page renders many
 * items belonging to the same author set. Cache is keyed by viewer; calling
 * it for many viewers in one request is fine but each unique viewer pays
 * once for getFollowing.
 */
final class PeepSoGraphService
{
    /** @var array<int, list<int>> viewer_id => ids of users they follow */
    private array $followingCache = [];

    /** @var array<int, array<int, bool>> viewer_id => target_id => bool */
    private array $isFollowingCache = [];

    /**
     * @return list<int> user IDs that $userId follows. Empty when none.
     */
    public function getFollowing(int $userId, int $limit = 200, int $offset = 0): array
    {
        if ($limit === 200 && $offset === 0 && isset($this->followingCache[$userId])) {
            return $this->followingCache[$userId];
        }

        $ids = PeepSoFollowerRepository::getFollowing($userId, $limit, $offset);

        if ($limit === 200 && $offset === 0) {
            $this->followingCache[$userId] = $ids;
        }
        return $ids;
    }

    /**
     * @return list<int> user IDs that follow $userId.
     */
    public function getFollowers(int $userId, int $limit = 200, int $offset = 0): array
    {
        return PeepSoFollowerRepository::getFollowers($userId, $limit, $offset);
    }

    public function isFollowing(int $viewerId, int $targetId): bool
    {
        if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) {
            return false;
        }

        if (isset($this->isFollowingCache[$viewerId][$targetId])) {
            return $this->isFollowingCache[$viewerId][$targetId];
        }

        // If we already loaded the full following set for this viewer, use it.
        if (isset($this->followingCache[$viewerId])) {
            $hit = in_array($targetId, $this->followingCache[$viewerId], true);
            $this->isFollowingCache[$viewerId][$targetId] = $hit;
            return $hit;
        }

        $hit = PeepSoFollowerRepository::isFollowing($viewerId, $targetId);
        $this->isFollowingCache[$viewerId][$targetId] = $hit;
        return $hit;
    }

    /**
     * Users that $viewerId follows who ALSO follow $targetId.
     * The raw input to social_proof composition (§O4 / §O4.1). Trust-tier
     * weighting and named/named+count split happen in the social-proof
     * composer, not here.
     *
     * @return list<int>
     */
    public function getMutuals(int $viewerId, int $targetId, int $limit = 50): array
    {
        if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) {
            return [];
        }
        return PeepSoFollowerRepository::getMutualFollowsOfTarget($viewerId, $targetId, $limit);
    }

    /**
     * @return array{following: int, followers: int}
     */
    public function getCounts(int $userId): array
    {
        return PeepSoFollowerRepository::getCounts($userId);
    }

    /**
     * Bulk variant — used when rendering a feed page where many items share
     * the same viewer. Returns a map of target_id => bool; caller composes
     * the followed/not-followed UI from it.
     *
     * @param list<int> $targetIds
     * @return array<int, bool>
     */
    public function isFollowingBulk(int $viewerId, array $targetIds): array
    {
        if ($viewerId <= 0 || $targetIds === []) {
            return [];
        }

        // Probe the SPECIFIC page authors, not an intersection against
        // getFollowing($viewerId) — that defaults to the newest 200 follows,
        // so a viewer who follows more than 200 accounts rendered any older-
        // followed author with a "Follow" button (and poisoned isFollowingCache
        // with false negatives). filterFollowed runs a bounded IN() over just
        // these ids. [audit M-B1]
        $lookup = [];
        foreach ($targetIds as $t) {
            $t = (int) $t;
            if ($t > 0 && $t !== $viewerId) {
                $lookup[$t] = true;
            }
        }

        $followedSet = [];
        if ($lookup !== []) {
            foreach (PeepSoFollowerRepository::filterFollowed($viewerId, array_keys($lookup)) as $id) {
                $followedSet[(int) $id] = true;
            }
        }

        $result = [];
        foreach ($targetIds as $targetId) {
            $t   = (int) $targetId;
            $hit = isset($followedSet[$t]);
            $result[$targetId] = $hit;
            if ($t > 0 && $t !== $viewerId) {
                $this->isFollowingCache[$viewerId][$t] = $hit;
            }
        }
        return $result;
    }
}
