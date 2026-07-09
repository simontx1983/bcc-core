<?php
/**
 * Fixture-backed stubs for PeepSoGroupWriterJoinGuardTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so the fake FQN classes below cannot
 * shadow the real BCC\Core classes anywhere else.
 *
 * Fixture shape ($GLOBALS['__bcc_gw_fixture']):
 *   status            ?string  what getMembershipStatus() reports for the row
 *   member_join_calls list<array{int,int}>  [groupId, userId] per call
 *   count_updates     list<int>             group ids whose counter refreshed
 *   actions           list<array>           do_action invocations
 *   warnings          list<string>          Logger::warning messages
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                $status = $GLOBALS['__bcc_gw_fixture']['status'] ?? null;
                return is_string($status) ? $status : null;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_gw_fixture']['warnings'][] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace {
    if (!class_exists('PeepSoGroupUser')) {
        class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private int $userId)
            {
            }

            public function member_join(): void
            {
                $GLOBALS['__bcc_gw_fixture']['member_join_calls'][] = [$this->groupId, $this->userId];
            }
        }
    }

    if (!class_exists('PeepSoGroupUsers')) {
        class PeepSoGroupUsers
        {
            public function __construct(private int $groupId)
            {
            }

            public function update_members_count(): void
            {
                $GLOBALS['__bcc_gw_fixture']['count_updates'][] = $this->groupId;
            }
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_gw_fixture']['actions'][] = array_merge([$hook], $args);
        }
    }
}
