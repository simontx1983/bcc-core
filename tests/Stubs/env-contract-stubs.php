<?php

/**
 * Stubs for the BCC_ENV contract tests: the wp-admin banner and the
 * identity endpoint that reports the same constant to the daily guard.
 *
 * ── WHY BOTH ARE EXERCISED FROM ONE STUB FILE ───────────────────────────
 * The whole point of the contract is that ONE configured value drives TWO
 * consumers that must not disagree. Testing them against separate fixtures
 * would let the fixtures diverge in exactly the way the production
 * configuration did.
 *
 * Everything here is a minimal shim, guarded by function_exists /
 * class_exists so a real WordPress (if the suite ever runs under core)
 * wins. Nothing here interprets BCC_ENV — the code under test must be the
 * only thing that reads it.
 *
 * @package BCC\Core\Tests
 */

declare(strict_types=1);

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void
    {
        // no-op; the identity endpoint fires litespeed_control_set_nocache
    }
}

if (!class_exists('WP_REST_Request')) {
    final class WP_REST_Request
    {
        /** @var array<string, string> */
        private array $headers = [];

        public function get_header(string $name): ?string
        {
            return $this->headers[strtolower($name)] ?? null;
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    final class WP_REST_Response
    {
        /** @var array<string, string> */
        public array $headers = [];

        /** @param mixed $data */
        public function __construct(public $data = null, public int $status = 200)
        {
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        /** @return mixed */
        public function get_data()
        {
            return $this->data;
        }
    }
}

/**
 * The three reads the identity endpoint makes.
 *
 * `prepare()` returns the SQL unchanged: these tests are about the `env`
 * field, not about SQL construction, and a fake that rewrote the query
 * would only be testing itself.
 */
if (!class_exists('BccEnvContractWpdb')) {
    final class BccEnvContractWpdb
    {
        public string $options = 'wp_options';

        /** @var array<string, string> */
        public array $rows = [
            'siteurl' => 'https://cms.bluecollarcrypto.io',
            'home'    => 'https://bluecollarcrypto.io',
        ];

        public int $optionsTableCount = 1;

        public function prepare(string $sql, ...$args): string
        {
            return $sql . '|' . implode('|', array_map('strval', $args));
        }

        /** @return mixed */
        public function get_var(string $sql)
        {
            if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                return (string) $this->optionsTableCount;
            }
            foreach ($this->rows as $name => $value) {
                if (str_ends_with($sql, '|' . $name)) {
                    return $value;
                }
            }

            return null;
        }
    }
}
