<?php
/**
 * Doubles for SuspensionStateTest: the REAL Permissions + ServiceLocator,
 * with the trust read service, the object cache, the current user and the
 * admin check faked.
 *
 * $GLOBALS['__susp'] drives them:
 *   answers   list of isSuspended() outcomes, consumed one per call:
 *             true / false, or 'unknown' (throws SuspensionUnknownException)
 *   calls     how many times isSuspended() actually ran
 *   admins    user ids for which user_can(…, 'manage_options') is true
 *   cache     the object cache (group:key => value)
 *
 * The fake service is declared at the FQN ServiceLocator's allowlist accepts
 * for TrustReadServiceInterface. That is safe ONLY because the consuming test
 * is #[RunTestsInSeparateProcesses]. Required from setUp(), never at file
 * scope.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null)
        {
            $found = array_key_exists($group . ':' . $key, $GLOBALS['__susp']['cache']);
            return $found ? $GLOBALS['__susp']['cache'][$group . ':' . $key] : false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__susp']['cache'][$group . ':' . $key] = $value;
            return true;
        }
    }
    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset($GLOBALS['__susp']['cache'][$group . ':' . $key]);
            return true;
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 0;
        }
    }
    if (!function_exists('user_can')) {
        /** @param int|object $user */
        function user_can($user, string $capability): bool
        {
            return in_array((int) $user, $GLOBALS['__susp']['admins'], true);
        }
    }
    if (!function_exists('apply_filters')) {
        /**
         * @param  mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            if ($hook === 'bcc.resolve.trust_read_service') {
                return new \BCC\Trust\Core\Application\TrustReadService();
            }
            return $value;
        }
    }
}

namespace BCC\Core\Log {
    // The real Logger writes under WP_CONTENT_DIR; record instead.
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__susp']['logs'][] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                $GLOBALS['__susp']['logs'][] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__susp']['logs'][] = $message;
            }
        }
    }
}

namespace BCC\Trust\Core\Application {
    if (!class_exists(TrustReadService::class, false)) {
        final class TrustReadService implements \BCC\Core\Contracts\TrustReadServiceInterface
        {
            public function isSuspended(int $userId): bool
            {
                $GLOBALS['__susp']['calls']++;
                $answer = array_shift($GLOBALS['__susp']['answers']);
                if ($answer === 'unknown') {
                    throw new \BCC\Core\Contracts\SuspensionUnknownException('simulated read failure');
                }
                return (bool) $answer;
            }

            public function getVoteById(int $voteId): ?array
            {
                return null;
            }

            public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
            {
                return [];
            }

            public function countActiveVotesForPage(int $pageId): int
            {
                return 0;
            }

            public function getVotesByIds(array $voteIds): array
            {
                return [];
            }

            public function lockActiveVoteForDispute(int $voteId): bool
            {
                return false;
            }
        }
    }
}
