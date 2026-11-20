<?php
/**
 * Environment for the F-459 meta-cache guard tests.
 *
 * ⚠⚠ THE WPDB DOUBLE MODELS THREE DISTINCT OUTCOMES, because the whole
 * point of F-459 is that two of them are indistinguishable through
 * `get_results()`:
 *
 *   OK            query() returns the row count, last_result holds THIS
 *                 query's rows, last_error is ''.
 *   DRIVER ERROR  query() returns false, last_error is set, and
 *                 last_result is RESET — because wpdb::query() has already
 *                 called flush() by the time the statement fails. This is
 *                 why a failure looks exactly like "no rows" to
 *                 get_results(): both give [].
 *   PRE-FLUSH BAIL  query() returns false, last_error stays EMPTY, and
 *                 last_result still holds the PREVIOUS query's rows —
 *                 wpdb::query() returns early on `! $this->ready` (a failed
 *                 reconnect) or when the `query` filter empties the SQL,
 *                 and both of those returns are ABOVE the flush() call.
 *
 * A double that only offered "error" and "no error" could not express the
 * third, and the third is the dangerous one: get_results() hands back
 * another query's rows with nothing to indicate it, so caching them stores
 * one object's meta against another's id. Verified against
 * wp-includes/class-wpdb.php, not assumed.
 *
 * The wp_cache_* shims are the real surface core uses: wp_cache_get_multiple
 * answers `false` for a miss (NOT null, and not an empty array), and
 * wp_cache_add_multiple adds only keys that are absent.
 */

declare(strict_types=1);

namespace BCC\Core\Meta\Tests {

if (!class_exists(__NAMESPACE__ . '\\MetaStore', false)) {
    final class MetaStore
    {
        /** @var array<string, array<int|string, mixed>> group => key => value */
        public static array $cache = [];

        /** @var list<string> every SQL string query() was given */
        public static array $queries = [];

        /** @var list<array<int|string, mixed>> every wp_cache_add_multiple payload */
        public static array $added = [];

        /** @var list<array{subsystem: string, event: string}> */
        public static array $metrics = [];

        /** @var list<string> */
        public static array $logs = [];

        public static function reset(): void
        {
            self::$cache   = [];
            self::$queries = [];
            self::$added   = [];
            self::$metrics = [];
            self::$logs    = [];
        }
    }

    final class MetaWpdb
    {
        public string $prefix   = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $usermeta = 'wp_usermeta';

        public string $last_error = '';

        /** @var mixed */
        public $last_result = [];

        /**
         * Rows the NEXT successful query returns, as objects.
         *
         * @var list<object>
         */
        public array $rows = [];

        /** 'ok' | 'driver_error' | 'pre_flush_bail' */
        public string $mode = 'ok';

        public string $error = 'MySQL server has gone away';

        /**
         * Rows left over from an EARLIER query, for the pre-flush bail: the
         * state that makes get_results() lie.
         *
         * @var list<object>
         */
        public array $staleRows = [];

        /** @return int|false */
        public function query(string $sql)
        {
            MetaStore::$queries[] = $sql;

            if ($this->mode === 'pre_flush_bail') {
                // ⚠ No flush(), so last_result keeps the previous rows and
                // last_error is NOT set. Only query()'s own `false` shows it.
                $this->last_result = $this->staleRows;
                return false;
            }

            if ($this->mode === 'driver_error') {
                // flush() has run: previous rows are gone, error is set.
                $this->last_result = [];
                $this->last_error  = $this->error;
                return false;
            }

            $this->last_error  = '';
            $this->last_result = $this->rows;

            return count($this->rows);
        }

        /**
         * Present so a mutation can swap trustedRows() for get_results() and
         * be measured. Faithful: it calls query(), DISCARDS the return value,
         * and hands back last_result — which is exactly the bug.
         *
         * @return list<object>
         */
        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->query($sql);
            return is_array($this->last_result) ? $this->last_result : [];
        }
    }
}

}

namespace BCC\Core\Log {

    use BCC\Core\Meta\Tests\MetaStore;

    /**
     * The real Logger writes under WP_CONTENT_DIR, which a pure-unit run has
     * not got. Record instead, in the house style of
     * tests/Stubs/suspension-state-stubs.php.
     *
     * ⚠ The `false` second argument is load-bearing: a plain
     * `class_exists(Logger::class)` would ASK THE AUTOLOADER, load the real
     * Logger, and make this guard always true — so the stub would never be
     * defined and the test would hit the real file writer.
     */
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                MetaStore::$logs[] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                MetaStore::$logs[] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                MetaStore::$logs[] = $message;
            }
        }
    }
}

namespace BCC\Core\Observability {

    use BCC\Core\Meta\Tests\MetaStore;

    /** Records instead of writing counters, so the metric can be asserted. */
    if (!class_exists(DegradationMetrics::class, false)) {
        final class DegradationMetrics
        {
            public const EVENT_ACTIVATION = 'activation';

            public static function record(string $subsystem, string $event = self::EVENT_ACTIVATION): void
            {
                MetaStore::$metrics[] = ['subsystem' => $subsystem, 'event' => $event];
            }
        }
    }
}

namespace {

    use BCC\Core\Meta\Tests\MetaStore;

    if (!function_exists('_get_meta_table')) {
        function _get_meta_table(string $type): string
        {
            return $type === 'user' ? 'wp_usermeta' : 'wp_postmeta';
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
        {
            return true;
        }
    }

    if (!function_exists('wp_cache_get_multiple')) {
        /**
         * @param  list<int|string> $keys
         * @return array<int|string, mixed> `false` for every miss, as core expects
         */
        function wp_cache_get_multiple(array $keys, string $group = ''): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = array_key_exists($key, MetaStore::$cache[$group] ?? [])
                    ? MetaStore::$cache[$group][$key]
                    : false;
            }
            return $out;
        }
    }

    if (!function_exists('wp_cache_add_multiple')) {
        /** @param array<int|string, mixed> $data */
        function wp_cache_add_multiple(array $data, string $group = ''): array
        {
            MetaStore::$added[] = $data;

            $out = [];
            foreach ($data as $key => $value) {
                $exists = array_key_exists($key, MetaStore::$cache[$group] ?? []);
                if (!$exists) {
                    MetaStore::$cache[$group][$key] = $value;
                }
                $out[$key] = !$exists;
            }
            return $out;
        }
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get($key, string $group = '', bool $force = false, &$found = null)
        {
            $found = array_key_exists($key, MetaStore::$cache[$group] ?? []);
            return $found ? MetaStore::$cache[$group][$key] : false;
        }
    }
}
