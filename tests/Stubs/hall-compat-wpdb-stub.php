<?php
/**
 * Recording $wpdb fake for HallRepoCompatWrappersTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so this global $wpdb fake and the
 * OBJECT / ARRAY_A constants cannot leak anywhere else.
 *
 * The fake records every prepared query it is asked to run and returns
 * deterministic, content-addressed fixtures (branching on the SQL text so
 * the multi-query getPrimaryHallForUsers path resolves correctly). Because
 * the six compat wrappers are pure pass-throughs, a wrapper and its Hall
 * counterpart, given equivalent input, issue a BYTE-IDENTICAL query
 * sequence and produce an IDENTICAL return — which is exactly what the
 * test asserts (delegation proof, no live DB).
 */

declare(strict_types=1);

namespace {

    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!class_exists('BccFakeWpdb', false)) {
        /**
         * Minimal recording stand-in for WordPress's wpdb. Only the
         * surface PeepSoGroupRepository's Hall reads touch is implemented.
         */
        final class BccFakeWpdb
        {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $usermeta = 'wp_usermeta';

            /** @var list<string> Recorded prepared queries, in call order. */
            public array $queries = [];

            /**
             * Deterministic prepare(): fold the args into the template so
             * identical (template, args) inputs render to identical strings.
             *
             * @param mixed ...$args
             */
            public function prepare(string $query, ...$args): string
            {
                $flat = [];
                foreach ($args as $a) {
                    $flat[] = is_scalar($a) ? (string) $a : gettype($a);
                }
                return $query . ' <<ARGS:' . implode('|', $flat) . '>>';
            }

            /** @return object|null */
            public function get_row(string $query, string $output = OBJECT)
            {
                $this->queries[] = $query;
                // Both findHallBySlug and findHallById select this shape.
                return (object) [
                    'id'           => '42',
                    'post_name'    => 'ethereum-hall',
                    'post_title'   => 'Ethereum Hall',
                    'member_count' => '3',
                ];
            }

            /**
             * @return list<object>|list<array<string,mixed>>
             */
            public function get_results(string $query, string $output = OBJECT): array
            {
                $this->queries[] = $query;

                // getPrimaryHallForUsers step 1: usermeta primary pointer.
                if (str_contains($query, $this->usermeta) && str_contains($query, 'meta_value')) {
                    return [
                        ['user_id' => '5', 'meta_value' => '42'],
                    ];
                }

                // findManyByIds (step 2 of getPrimaryHallForUsers): selects
                // post_content in addition to the directory columns.
                if (str_contains($query, 'post_content')) {
                    return [
                        (object) [
                            'id'           => '42',
                            'post_name'    => 'ethereum-hall',
                            'post_title'   => 'Ethereum Hall',
                            'post_content' => 'The Ethereum union hall.',
                            'member_count' => '3',
                        ],
                    ];
                }

                // listHalls directory rows.
                return [
                    (object) [
                        'id'           => '42',
                        'post_name'    => 'ethereum-hall',
                        'post_title'   => 'Ethereum Hall',
                        'member_count' => '3',
                    ],
                ];
            }

            /** @return list<string> */
            public function get_col(string $query): array
            {
                $this->queries[] = $query;
                // findUsersByPrimaryHall: user ids as numeric strings.
                return ['5', '6'];
            }

            /** @return string */
            public function get_var(string $query): string
            {
                $this->queries[] = $query;
                // countHalls COUNT(*).
                return '7';
            }
        }
    }
}
