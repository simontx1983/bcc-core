<?php
/**
 * Doubles for PublicTrendingHashtagsTest (F-168): the REAL
 * PeepSoHashtagRepository::getTrendingPubliclyVisible(), with $wpdb and the
 * transient API faked.
 *
 * $GLOBALS['__pt'] drives them:
 *   transients  key => value (delete_transient() removes it)
 *   ttl         key => seconds the last set_transient() asked for
 *   results     list of scripted query outcomes, consumed one per query():
 *               a list<object> (rows), false (query() fails), or
 *               ['error' => '...'] (query() "succeeds" but sets last_error)
 *   sql         list<string> every statement query() received, rendered
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__pt']['transients'][$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__pt']['transients'][$key] = $value;
            $GLOBALS['__pt']['ttl'][$key]        = $ttl;
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            $had = array_key_exists($key, $GLOBALS['__pt']['transients']);
            unset($GLOBALS['__pt']['transients'][$key]);
            return $had;
        }
    }

    if (!class_exists('PublicTrendingFakeWpdb', false)) {
        final class PublicTrendingFakeWpdb
        {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $last_error = '';
            /** @var list<object>|null */
            public ?array $last_result = null;

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $next = 0;
                $sql  = (string) preg_replace_callback('/%%|%[ds]/', static function (array $m) use (&$next, $args): string {
                    if ($m[0] === '%%') {
                        return '%';
                    }
                    $value = $args[$next++] ?? '<<UNBOUND>>';
                    return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
                }, $query);
                \PHPUnit\Framework\TestCase::assertSame(count($args), $next, 'every bound argument must have a placeholder');
                return (string) preg_replace('/\s+/', ' ', $sql);
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $GLOBALS['__pt']['sql'][] = $sql;
                $outcome = array_shift($GLOBALS['__pt']['results']);
                if ($outcome === false || $outcome === null) {
                    // A failure path that leaves last_result STALE, like wpdb's !ready guard.
                    return false;
                }
                if (is_array($outcome) && isset($outcome['error'])) {
                    $this->last_error = (string) $outcome['error'];
                    return 0;
                }
                $this->last_result = $outcome;
                return count($outcome);
            }
        }
    }
}
