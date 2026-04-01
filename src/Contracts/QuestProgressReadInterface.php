<?php
/**
 * Quest Progress Read Interface
 *
 * Cross-plugin contract for reading quest progress. Consumed by
 * peepso-integration (UI), onchain-signals (wallet quest), etc.
 * Provided by bcc-trust-engine via ServiceLocator filter hook.
 *
 * @package BCC\Core\Contracts
 */

namespace BCC\Core\Contracts;

interface QuestProgressReadInterface {

    /**
     * Get the quest multiplier for a user (1.0 = no quests, up to ~1.30).
     */
    public function getMultiplier(int $userId): float;

    /**
     * Check if a user has completed a specific quest.
     */
    public function hasCompleted(int $userId, string $slug): bool;

    /**
     * Check if a user has unlocked a capability through any completed quest.
     */
    public function hasUnlocked(int $userId, string $capability): bool;

    /**
     * Get full progress for a user (quests, multiplier, completion stats).
     *
     * @return array{
     *     quests: array<string, array{label: string, hint: string, done: bool, completed_at: ?string, weight_bonus: float, unlocks: string[], category: string}>,
     *     multiplier: float,
     *     completed_count: int,
     *     total_count: int,
     *     pct: int
     * }
     */
    public function getProgress(int $userId): array;
}
