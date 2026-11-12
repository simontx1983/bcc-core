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
 *   read_failed       bool     getMembershipStatusOrFail() returns false
 *   member_join_calls list<array{int,int}>  [groupId, userId] per call
 *   member_leave_calls list<array{int,int}> [groupId, userId] per call
 *   member_modify_calls list<array>         [groupId, userId, role, invitedBy, acceptedBy]
 *   count_updates     list<int>             group ids whose counter refreshed
 *   actions           list<array>           do_action invocations
 *   warnings          list<string>          Logger::warning messages
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            public static function getMembershipStatusOrFail(int $userId, int $groupId): string|null|false
            {
                if (!empty($GLOBALS['__bcc_gw_fixture']['read_failed'])) {
                    return false;
                }
                $status = $GLOBALS['__bcc_gw_fixture']['status'] ?? null;
                return is_string($status) ? $status : null;
            }

            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                $status = $GLOBALS['__bcc_gw_fixture']['status'] ?? null;
                return is_string($status) ? $status : null;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupMembershipWriteRepository::class, false)) {
        /**
         * Fixture keys: insert_result / promote_result / delete_result
         * (int 0|1, or false = the statement failed; default 1). Calls are
         * recorded in writes[] as [method, ...args].
         */
        class PeepSoGroupMembershipWriteRepository
        {
            public static function insertPendingIfAbsent(int $userId, int $groupId, string $status, ?int $invitedBy): int|false
            {
                $GLOBALS['__bcc_gw_fixture']['locked_writes'][] = \BCC\Core\DB\AdvisoryLock::anyHeld();
                $GLOBALS['__bcc_gw_fixture']['writes'][] = ['insert', $userId, $groupId, $status, $invitedBy];
                return $GLOBALS['__bcc_gw_fixture']['insert_result'] ?? 1;
            }

            public static function promotePending(int $userId, int $groupId, string $fromStatus, ?int $acceptedBy): int|false
            {
                $GLOBALS['__bcc_gw_fixture']['locked_writes'][] = \BCC\Core\DB\AdvisoryLock::anyHeld();
                $GLOBALS['__bcc_gw_fixture']['writes'][] = ['promote', $userId, $groupId, $fromStatus, $acceptedBy];
                return $GLOBALS['__bcc_gw_fixture']['promote_result'] ?? 1;
            }

            public static function deletePending(int $userId, int $groupId, string $status): int|false
            {
                $GLOBALS['__bcc_gw_fixture']['locked_writes'][] = \BCC\Core\DB\AdvisoryLock::anyHeld();
                $GLOBALS['__bcc_gw_fixture']['writes'][] = ['delete', $userId, $groupId, $status];
                return $GLOBALS['__bcc_gw_fixture']['delete_result'] ?? 1;
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        /**
         * Fixture keys: lock_busy (bool) makes acquire() fail; lock_calls
         * records [acquire|release, key]; held is the set of held keys.
         */
        class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                $GLOBALS['__bcc_gw_fixture']['lock_calls'][] = ['acquire', $key];
                if (!empty($GLOBALS['__bcc_gw_fixture']['lock_busy'])) {
                    return false;
                }
                $GLOBALS['__bcc_gw_fixture']['held'][$key] = true;
                return true;
            }

            public static function release(string $key): bool
            {
                $GLOBALS['__bcc_gw_fixture']['lock_calls'][] = ['release', $key];
                unset($GLOBALS['__bcc_gw_fixture']['held'][$key]);
                return true;
            }

            /** Test helper: is any pair lock held right now? */
            public static function anyHeld(): bool
            {
                return !empty($GLOBALS['__bcc_gw_fixture']['held']);
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
                $GLOBALS['__bcc_gw_fixture']['locked_writes'][] = \BCC\Core\DB\AdvisoryLock::anyHeld();
                $GLOBALS['__bcc_gw_fixture']['member_join_calls'][] = [$this->groupId, $this->userId];
            }

            public function member_leave(): void
            {
                $GLOBALS['__bcc_gw_fixture']['locked_writes'][] = \BCC\Core\DB\AdvisoryLock::anyHeld();
                $GLOBALS['__bcc_gw_fixture']['member_leave_calls'][] = [$this->groupId, $this->userId];
            }

            public function member_modify(string $role, ?int $invitedBy = null, ?int $acceptedBy = null): bool
            {
                $GLOBALS['__bcc_gw_fixture']['member_modify_calls'][] = [$this->groupId, $this->userId, $role, $invitedBy, $acceptedBy];
                return true;
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
