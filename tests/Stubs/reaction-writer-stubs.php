<?php
/**
 * Fixture-backed stubs for PeepSoReactionWriterTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so the fake FQN classes below cannot
 * shadow the real BCC\Core classes anywhere else.
 *
 * Fixture shape ($GLOBALS['__bcc_rw_fixture']):
 *   external_id ?int       what init() stamps on act_external_id
 *                          (null simulates a missing/deleted activity)
 *   init_calls  list<int>  act ids passed to init()
 *   set_calls   list<int>  reaction type ids passed to user_reaction_set()
 *   reset_calls int        user_reaction_reset() invocation count
 *   constructed int        model constructor count
 */

declare(strict_types=1);

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
            }
        }
    }
}

namespace {
    if (!class_exists('PeepSoReactionsModel')) {
        class PeepSoReactionsModel
        {
            /** @var mixed */
            public $act_external_id;

            public function __construct()
            {
                $GLOBALS['__bcc_rw_fixture']['constructed']++;
            }

            public function init(?int $act_id = null): void
            {
                $GLOBALS['__bcc_rw_fixture']['init_calls'][] = (int) $act_id;
                $this->act_external_id = $GLOBALS['__bcc_rw_fixture']['external_id'];
            }

            public function user_reaction_set(int $react_id): void
            {
                $GLOBALS['__bcc_rw_fixture']['set_calls'][] = $react_id;
            }

            public function user_reaction_reset(bool $is_delete = true): void
            {
                $GLOBALS['__bcc_rw_fixture']['reset_calls']++;
            }
        }
    }
}
