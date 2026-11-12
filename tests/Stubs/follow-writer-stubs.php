<?php
/**
 * F-30 stubs for PeepSoFollowWriter: the block read, PeepSo's follower
 * class and the uf_id read-back, all at their FQNs. Loaded only by
 * PeepSoFollowWriterBlockGateTest, which runs in separate processes.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        final class PeepSoBlockRepository
        {
            /** @var bool|null the verdict isMutuallyBlockedOrUnknown() returns */
            public static ?bool $verdict = false;

            /** @var list<array{int, int}> */
            public static array $asked = [];

            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                self::$asked[] = [$userA, $userB];
                return self::$verdict;
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(DegradationMetrics::class, false)) {
        final class DegradationMetrics
        {
            public static function record(string $subsystem, string $event): void
            {
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace {
    if (!class_exists('PeepSoUserFollower', false)) {
        final class PeepSoUserFollower
        {
            /** @var list<array{int, int, bool}> (passive, active, create) */
            public static array $constructed = [];

            public function __construct(int $passiveUserId, int $activeUserId, bool $create = false)
            {
                self::$constructed[] = [$passiveUserId, $activeUserId, $create];
            }
        }
    }
}
