<?php
/**
 * Fixture-backed stubs for PeepSoGroupWriterTransferTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so the fake FQN classes below cannot
 * shadow the real BCC\Core classes anywhere else. Same isolation pattern
 * as group-writer-stubs.php.
 *
 * Fixture shape ($GLOBALS['__bcc_gt_fixture']):
 *   group_id          int      what PeepSoGroup resolves its id to (0 = missing)
 *   owner_id          int      current owner per the PeepSoGroup model
 *   statuses          array<int, ?string>  per-user getMembershipStatus answers
 *   modify_ok         array<int, bool>     per-user member_modify verdicts (default true)
 *   modify_calls      list<array{int,int,string}>  [groupId, userId, role]
 *   updates           list<array>          PeepSoGroup->update() payloads
 *   actions           list<array>          do_action invocations
 *   errors            list<string>         Logger::error messages
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                unset($groupId);
                $statuses = $GLOBALS['__bcc_gt_fixture']['statuses'] ?? [];
                $status   = $statuses[$userId] ?? null;
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
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_gt_fixture']['errors'][] = $message;
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(DegradationMetrics::class, false)) {
        class DegradationMetrics
        {
            public static function record(string $subsystem, string $event): void
            {
                unset($subsystem, $event);
            }
        }
    }
}

namespace {
    if (!class_exists('PeepSoGroup')) {
        class PeepSoGroup
        {
            public function __construct(private int $requestedId)
            {
            }

            /** @return int */
            public function get(string $prop)
            {
                if ($prop === 'id') {
                    $resolved = (int) ($GLOBALS['__bcc_gt_fixture']['group_id'] ?? 0);
                    // Mirror PeepSo: an unresolvable id never equals the request.
                    return $resolved === $this->requestedId ? $resolved : 0;
                }
                if ($prop === 'owner_id') {
                    return (int) ($GLOBALS['__bcc_gt_fixture']['owner_id'] ?? 0);
                }
                return 0;
            }

            /** @param array<string, mixed> $data */
            public function update(array $data): void
            {
                $GLOBALS['__bcc_gt_fixture']['updates'][] = $data;
            }
        }
    }

    if (!class_exists('PeepSoGroupUser')) {
        class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private int $userId)
            {
            }

            public function member_modify(string $role): bool
            {
                $GLOBALS['__bcc_gt_fixture']['modify_calls'][] = [$this->groupId, $this->userId, $role];
                $ok = $GLOBALS['__bcc_gt_fixture']['modify_ok'] ?? [];
                return (bool) ($ok[$this->userId] ?? true);
            }
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_gt_fixture']['actions'][] = array_merge([$hook], $args);
        }
    }
}
