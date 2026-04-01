<?php
/**
 * Null Quest Progress Read
 *
 * Safe fallback when bcc-trust-engine is not active. Returns base
 * multiplier (1.0) and empty progress — quests have zero effect.
 *
 * @package BCC\Core\NullServices
 */

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\QuestProgressReadInterface;

class NullQuestProgressRead implements QuestProgressReadInterface {

    public function getMultiplier(int $userId): float {
        return 1.0;
    }

    public function hasCompleted(int $userId, string $slug): bool {
        return false;
    }

    public function hasUnlocked(int $userId, string $capability): bool {
        return false;
    }

    public function getProgress(int $userId): array {
        return [
            'quests'          => [],
            'multiplier'      => 1.0,
            'completed_count' => 0,
            'total_count'     => 0,
            'pct'             => 0,
        ];
    }
}
