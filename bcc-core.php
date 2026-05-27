<?php
/**
 * Plugin Name: Blue Collar Crypto – Core
 * Description: Shared infrastructure for the BCC plugin ecosystem: permissions, PeepSo adapter, DB helpers, caching, logging, and utilities. Production requires a persistent object cache (Redis/Memcached) for rate limiting and API budget enforcement.
 * Version:     1.0.7
 * Author:      Blue Collar Labs LLC
 * Text Domain: bcc-core
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * GitHub Plugin URI: https://github.com/simontx1983/bcc-core
 * Primary Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Extension Check ─────────────────────────────────────────────
// GMP must be checked BEFORE defining BCC_CORE_VERSION so downstream
// plugins that gate on defined('BCC_CORE_VERSION') correctly see core
// as unavailable when the autoloader cannot load.

if (!extension_loaded('gmp')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Blue Collar Crypto – Core requires the GMP PHP extension. Please enable it in your php.ini.', 'bcc-core');
        echo '</p></div>';
    });
    return;
}

// ── Composer Autoloader ──────────────────────────────────────────

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── Constants (defined only after core is fully functional) ──────

define('BCC_CORE_VERSION', '1.0.7');
define('BCC_CORE_PATH', plugin_dir_path(__FILE__));
define('BCC_CORE_URL', plugin_dir_url(__FILE__));

// ── Rate-limiter readiness enforcement ──────────────────────────
// BCC requires a safe rate-limiter backend — either the trust-engine's
// atomic RateLimiter OR a persistent object cache (Redis / Memcached).
// Throttle::isReady() is the single source of truth; Throttle::allow()
// FAILS CLOSED (denies every action) when isReady() returns false so a
// missing backend cannot silently disengage abuse protection.
//
// This notice runs late on plugins_loaded so all BCC plugins — including
// bcc-trust, which provides the preferred RateLimiter class —
// have had a chance to register before we probe the environment.

add_action('plugins_loaded', function (): void {
    if (\BCC\Core\Security\Throttle::isReady()) {
        return;
    }

    // Log once per request so the problem shows up in whatever log
    // aggregation the operator uses, not just the admin UI.
    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
        \BCC\Core\Log\Logger::error(
            '[bcc-core] Rate limiter NOT ready — denying all throttled actions. ' .
            'Install Redis / Memcached or activate bcc-trust (RateLimiter).'
        );
    }
}, 100);

add_action('admin_notices', function () {
    if (\BCC\Core\Security\Throttle::isReady()) {
        return;
    }
    // Blocking red notice — intentionally not dismissible. The deny-all
    // fail-closed behaviour in Throttle::allow means EVERY rate-limited
    // action (disputes, voting, wallet verification, report-user, etc.)
    // is returning 429 until the operator provisions a backend.
    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html('Blue Collar Crypto — Rate limiter offline:'),
        esc_html(
            'BCC requires Redis or a persistent object cache for safe rate limiting. '
            . 'No backend is currently available, so all throttled actions (disputes, voting, '
            . 'wallet verification, user reports) are being DENIED by default. Install Redis / '
            . 'Memcached or activate bcc-trust to restore service.'
        )
    );
});

// ── ServiceLocator freeze ───────────────────────────────────────
// After all plugins have loaded, freeze the ServiceLocator cache so
// late-registered filters cannot replace already-resolved services.

add_action('plugins_loaded', [\BCC\Core\ServiceLocator::class, 'freeze'], PHP_INT_MAX);

// ── Logger secret pre-seed ──────────────────────────────────────
// Logger::ensureInit() writes `bcc_log_file_secret` to wp_options on the
// first log call.  If that first call happens inside a transaction that
// subsequently rolls back (e.g. Logger::error from a catch block inside
// TransactionManager::run), the secret write is rolled back with it and
// the randomized log filename changes across requests.  Pre-seed at
// activation so the option exists before any transactional code runs.
register_activation_hook(__FILE__, function () {
    if (!get_option('bcc_log_file_secret')) {
        add_option('bcc_log_file_secret', bin2hex(random_bytes(16)), '', false);
    }
});

// ── Environment banner (Operator OS v1 Phase 1) ────────────────
// Shows a colored banner on every wp-admin page identifying the
// environment (prod/staging/dev), so an operator cannot confuse
// envs while performing a destructive action. Reads BCC_ENV from
// wp-config.php.
\BCC\Core\Admin\EnvBanner::register();

// ── System Health admin page (Operator OS v1 Phase 2) ──────────
// wp-admin renderer for /bcc/v1/system/health so operators don't
// have to curl the JSON. Top-level menu "BCC System" → "Health".
\BCC\Core\Admin\SystemHealthPage::register();

// ── Cron admin page (Operator OS v1 Phase 2) ──────────────────
// wp-admin renderer for the cron-system state + drift detector for
// the V2-NFT cron-drift class. Sub-menu under "BCC System" → "Cron".
\BCC\Core\Admin\CronPage::register();

// bcc-core's own canonical cron hook (rate-limiter cleanup). Other
// bcc-* plugins contribute their own via the bcc_expected_cron_hooks
// filter at boot.
add_filter('bcc_expected_cron_hooks', function (array $hooks): array {
    $hooks['bcc_core_rl_cleanup'] = [
        'interval'    => 'bcc_thirty_minutes',
        'source'      => 'bcc-core',
        'description' => 'Rate-limiter wp_options + transient cleanup',
    ];
    return $hooks;
});

// ── API Keys status admin page (Operator OS v1 Phase 2) ─────────
// wp-admin renderer for the secret/API-key inventory. STATUS ONLY —
// never editable, never raw values. Sub-menu under "BCC System" →
// "API Keys".
\BCC\Core\Admin\ApiKeysPage::register();

// ── Developer admin page (Operator OS v1 Phase 3) ───────────────
// Engineer-grade internals — filter-based panel registry. Each
// plugin contributes its own panel via apply_filters(
// 'bcc_developer_panels', []). Sub-menu under "BCC System" →
// "Developer".
\BCC\Core\Admin\DeveloperPage::register();

// ── Hide WP defaults (two-engineer audit follow-up) ─────────────
// Removes Posts / Comments / Media / WP Pages CPT / Appearance and
// the Reading / Discussion / Permalinks / Writing Settings
// sub-pages — BCC is headless, none of these are used. Filter
// hooks (bcc_hide_wp_defaults_enabled / *_menus / *_settings_sub)
// expose the behavior to operators who want to opt back in.
\BCC\Core\Admin\HideWpDefaults::register();

// bcc-core's own secret: the shared challenge for the internal
// Polkadot signature verifier route (called by
// PolkadotSignatureVerifier → bcc-frontend /api/internal/verify-
// wallet-signature). Missing = Polkadot wallet-link broken.
add_filter('bcc_api_keys_inventory', function (array $inventory): array {
    $inventory['BCC_INTERNAL_VERIFY_SECRET'] = [
        'source'      => 'bcc-core',
        'severity'    => 'critical',
        'description' => 'Internal-route shared challenge for Polkadot signature verifier (PolkadotSignatureVerifier → Next.js verify route).',
    ];
    return $inventory;
});

// ── Cross-plugin suspension cache invalidation ─────────────────
// Trust-engine fires `bcc_user_suspension_changed` when a user's
// suspension status changes. This ensures Permissions picks it up
// immediately instead of waiting for the 60-second cache TTL.
\BCC\Core\Permissions\Permissions::registerHooks();

// ── Non-open group cache invalidation ──────────────────────────
// PeepSoGroupRepository::getNonOpenGroupIds() backs the §4.7.x main-
// feed leak gate (closed/secret/NFT-gated group posts hidden from
// non-members in /bcc/v1/feed and /bcc/v1/feed/hot). The list is
// cached via the §5 generation-counter pattern; any write to a
// group's `peepso_group_privacy` post-meta must bump the generation
// so the next read recomputes. Bound to all three meta-write hooks
// (added / updated / deleted) for defense-in-depth — PeepSo's
// privacy-toggle path uses `update_post_meta` which fires either
// `added_post_meta` (first write) or `updated_post_meta`
// (subsequent), and a privacy-row deletion (rare, but possible via
// admin tools) leaves the group as "no row = open" so the cache
// must drop it from the non-open list.
// First positional arg differs across the three actions (int meta_id
// for added/updated, list<string> meta_ids for deleted). We don't read
// it, so leave it untyped on the closure signature.
$bccBustNonOpenGroupCache = static function ($_metaIdOrIds, $_objectId, $metaKey): void {
    if (is_string($metaKey) && $metaKey === 'peepso_group_privacy') {
        \BCC\Core\Repositories\PeepSoGroupRepository::bustNonOpenGroupIdsCache();
    }
};
add_action('added_post_meta',   $bccBustNonOpenGroupCache, 10, 3);
add_action('updated_post_meta', $bccBustNonOpenGroupCache, 10, 3);
add_action('deleted_post_meta', $bccBustNonOpenGroupCache, 10, 3);

// ── Legacy option cleanup (post-consolidation one-shot) ─────────
// bcc-disputes and bcc-onchain-signals were merged into bcc-trust.
// Their option rows in wp_options (settings, counters, transients)
// no longer have a consumer, so they bloat the table and risk
// stale-cache collisions if any code path ever reads them again.
// Gated on a version-scoped option sentinel so the sweep runs at
// most once per install and is skipped on every subsequent boot.
add_action('init', function (): void {
    $sentinel = 'bcc_core_legacy_cleanup_v1';
    if (get_option($sentinel)) {
        return;
    }

    // Serialize across PHP workers — without the lock, two concurrent
    // requests on a fresh deploy would both enter the DELETE path
    // before either writes the sentinel.
    if (!\BCC\Core\DB\AdvisoryLock::acquire($sentinel, 0)) {
        return;
    }

    try {
        // Re-check under lock in case another worker completed the
        // sweep between our initial get_option() and acquire().
        if (get_option($sentinel)) {
            return;
        }

        // Only prefixes that were owned by the DELETED plugins are
        // listed here. bcc_core_* and bcc_trust_* are still live.
        // Transient twins (_transient_<key>, _transient_timeout_<key>)
        // are included so expired transient metadata is also swept.
        $legacyPrefixes = [
            'bcc_disputes_',
            'bcc_onchain_',
            'bcc_signals_',
            '_transient_bcc_disputes_',
            '_transient_bcc_onchain_',
            '_transient_bcc_signals_',
            '_transient_timeout_bcc_disputes_',
            '_transient_timeout_bcc_onchain_',
            '_transient_timeout_bcc_signals_',
        ];

        $totalDeleted = 0;
        $errors       = [];
        foreach ($legacyPrefixes as $prefix) {
            $deleted = \BCC\Core\Repositories\OptionCleanupRepository::deleteByPrefix($prefix);
            if ($deleted === null) {
                $errors[] = [
                    'prefix' => $prefix,
                    'error'  => \BCC\Core\Repositories\OptionCleanupRepository::lastError(),
                ];
                continue;
            }
            $totalDeleted += $deleted;
        }

        // Write the sentinel regardless of per-prefix errors — we don't
        // want a single transient DB hiccup to loop this on every init.
        // Errors are logged so operators can re-run manually if needed.
        update_option($sentinel, [
            'completed_at'  => gmdate('c'),
            'total_deleted' => $totalDeleted,
            'errors'        => $errors,
        ], false);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            if (!empty($errors)) {
                \BCC\Core\Log\Logger::warning('[bcc-core] legacy option cleanup completed with errors', [
                    'total_deleted' => $totalDeleted,
                    'errors'        => $errors,
                ]);
            } elseif ($totalDeleted > 0) {
                \BCC\Core\Log\Logger::info('[bcc-core] legacy option cleanup', [
                    'total_deleted' => $totalDeleted,
                ]);
            }
        }
    } finally {
        \BCC\Core\DB\AdvisoryLock::release($sentinel);
    }
}, 5);

// ── Rate-limit row cleanup ──────────────────────────────────────
// Throttle's DB fallback and RateLimiter write rows to wp_options
// that never auto-expire. This hourly cron garbage-collects expired
// entries in bounded batches to avoid table-locking.

add_action('bcc_core_rl_cleanup', function () {
    // Advisory lock prevents overlapping runs when WP-Cron fires
    // multiple times (duplicate spawns, external cron + wp-cron race).
    if (!\BCC\Core\DB\AdvisoryLock::acquire('bcc_core_rl_cleanup', 0)) {
        return;
    }

    try {
        // Clean both prefixes: _bcc_rl_ (Throttle) and _transient_bcc_rl_ (RateLimiter).
        // Use range scan instead of LIKE wildcards to avoid full table scan.
        // Loop up to 10 times (10000 rows max per cron tick).
        $ranges = [
            ['_bcc_rl_',           '_bcc_rl_~'],
            ['_transient_bcc_rl_', '_transient_bcc_rl_~'],
        ];

        $totalDeleted = 0;
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            $iterations = 0;
            do {
                $deleted = \BCC\Core\Repositories\OptionCleanupRepository::deleteExpiredRange(
                    $rangeStart,
                    $rangeEnd,
                    1000
                );

                if ($deleted === null) {
                    \BCC\Core\Log\Logger::error('[bcc-core] rl_cleanup DELETE failed', [
                        'range' => $rangeStart,
                        'error' => \BCC\Core\Repositories\OptionCleanupRepository::lastError(),
                    ]);
                    break;
                }

                $totalDeleted += $deleted;
                $iterations++;
            } while ($deleted >= 1000 && $iterations < 10);
        }

        if ($totalDeleted > 0) {
            \BCC\Core\Log\Logger::info('[bcc-core] rl_cleanup', [
                'deleted' => $totalDeleted,
            ]);
        }
    } finally {
        \BCC\Core\DB\AdvisoryLock::release('bcc_core_rl_cleanup');
    }
});

add_filter('cron_schedules', function (array $schedules): array {
    if (!isset($schedules['bcc_thirty_minutes'])) {
        $schedules['bcc_thirty_minutes'] = [
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (BCC RL Cleanup)',
        ];
    }
    return $schedules;
});

add_action('init', function () {
    // Run every 30 minutes — prevents row accumulation during traffic spikes.
    if (!wp_next_scheduled('bcc_core_rl_cleanup')) {
        wp_schedule_event(time(), 'bcc_thirty_minutes', 'bcc_core_rl_cleanup');
    }
}, 99);

// ── System health filter contributors ──────────────────────────
// Phase 3 of the post-stabilization observability initiative
// (2026-05-09). Each plugin contributes a top-level block via
// `add_filter('bcc_system_health', ...)`. bcc-core contributes:
//   - throttle: full Throttle::health() snapshot (richer than the
//     inline `cache.rate_limiter_degraded` flag the endpoint already
//     surfaces — adds backend kind + last_success_ts + readiness).
//   - degradation_metrics: aggregated current+previous-hour counters
//     for every subsystem instrumented via DegradationMetrics. The
//     `any_active` boolean is the single-bit triage signal.
//
// The canonical-subsystem list lives here (in bcc-core) and is the
// single discoverable place where "what subsystems get summarized in
// /system/health?" is answered. New subsystems wired into
// DegradationMetrics in any plugin should add their (subsystem, events)
// tuple to this map AND record themselves in the
// docs/pattern-registry.md Observability section.

add_filter('bcc_system_health', function (array $health): array {
    $health['throttle'] = \BCC\Core\Security\Throttle::health();

    $health['degradation_metrics'] = \BCC\Core\Observability\DegradationMetrics::healthSnapshot([
        // bcc-core subsystems
        'throttle'                 => ['activation'],
        // null_trust_read keeps per-method events because the security
        // posture differs: is_suspended + lock_active_vote_for_dispute
        // are fail-closed (deny access); other methods are fail-open
        // empty (UX degraded but not blocking). Operators triage them
        // differently.
        'null_trust_read'          => [
            'activation',
            'is_suspended',
            'lock_active_vote_for_dispute',
            'eligible_panelists',
        ],
        // Other NullServices use a single `activation` event per service.
        // The "is this NullService active?" signal is the operationally
        // useful one; per-method breakdown is recoverable from logs if
        // needed. All 10 below activate when bcc-trust is not bound.
        'null_dispute_adjudication' => ['activation'],
        'null_score_read'           => ['activation'],
        'null_score_contributor'    => ['activation'],
        'null_page_owner'           => ['activation'],
        'null_wallet_link_read'     => ['activation'],
        'null_wallet_link_write'    => ['activation'],
        'null_wallet_signal_write'  => ['activation'],
        'null_onchain_data_read'    => ['activation'],
        'null_trending_data'        => ['activation'],
        // (NullRecalcQueueRead intentionally not instrumented — its
        // null-return is already detected by the inline health endpoint
        // as `recalcSource: 'unavailable'`.)
        // peepso_absence — every BCC writer/repo on the PeepSo boundary
        // contributes a unique event so admins see exactly which surface
        // is silently no-opping. Phase 1.5 (2026-05-09) expanded this to
        // cover all 18 V-11 guards across bcc-core/src/PeepSo/* +
        // bcc-core/src/Repositories/PeepSoMessageRepository.
        'peepso_absence'       => [
            'status_writer_create',
            'comment_writer_add',
            'gif_writer_create',
            'photo_writer_create',
            'follow_writer_follow',
            'follow_writer_unfollow',
            'group_writer_join',
            'group_writer_leave',
            'notification_writer_send',
            'reaction_writer_set',
            'reaction_writer_remove',
            'message_writer_send_new',
            'message_writer_send_in_conversation',
            'message_repo_unread_count',
            'message_repo_is_participant',
            'message_repo_root_conversation_id',
            'message_repo_participants',
            'message_repo_mark_viewed',
        ],
        // bcc-search subsystems
        'search_lkg'           => ['served', 'unavailable_503'],
        // bcc-trust subsystems
        'read_model_fallback'  => ['legacy_aggregation'],
        // audit_log_swallow — silent-catch read paths that are supposed
        // to be reliable, plus the WRITE path inside AuditLogger::log()
        // itself (the only swallow that §VIII.30 deliberately requires:
        // an audit-log write failure must NEVER break the mutation
        // path). Sustained activation = the audit subsystem is
        // unhealthy; admins see it via /system/health before forensic
        // queries discover the gap on incident review. Phase 1.8
        // (2026-05-11) added the two owner-lookup swallow events;
        // 2026-05-13 added the log_write_failed write-path source.
        'audit_log_swallow'    => [
            'score_mutation_before_snapshot',   // Phase 1 — ScoreMutationLogger::readCurrentScore
            'discovery_owner_verified_status',  // Phase 1.8 — PageDiscoveryService verified-badge lookup
            'log_write_failed',                 // 2026-05-13 — AuditLogger::log insert returned false
        ],
        // account_security_mail — bcc-trust AccountSecurityMailer wp_mail
        // failures. These are the side-channel emails that warn a user
        // their credentials / identity-bearing artifacts changed (email,
        // password, delete, wallet link/unlink). Sustained activation =
        // mail subsystem unhealthy on a security-critical surface; ops
        // should treat this as a P1 alert, not just a warning. Per
        // AccountSecurityMailer's docblock, failures here mean the user
        // who was supposed to receive a canary signal did not — the
        // audit_log row is the only remaining trail.
        'account_security_mail' => [
            'email_changed_send_failed',
            'password_changed_send_failed',
            'account_deleted_send_failed',
            'wallet_linked_send_failed',
            'wallet_unlinked_send_failed',
            'sessions_revoked_all_send_failed',  // Tier D (2026-05-16) — /auth/logout-everywhere confirmation
        ],
        // legacy_ajax — Phase 1.7 (2026-05-09) instrumentation of
        // suspected-dead AJAX handlers (V-08 candidates). Audit found
        // no caller in any JS / PHP / TS / bcc-frontend. 30-day zero-hit
        // window → safe to retire per Stabilization Plan V-08 Phase D.
        // Sustained nonzero activation = an external consumer exists
        // that the in-repo audit missed (cron / wp-cli / partner script
        // / cached pre-deploy browser tab) — investigate before retire.
        //
        // 2026-05-25: the 6 wallet/collection AJAX handlers (wallet_challenge,
        // wallet_verify, wallet_disconnect, wallet_set_primary, wallet_list,
        // collection_toggle_profile) were retired under the fresh-install
        // policy without waiting for the full 30-day window; SPA + claim
        // flows have always used the REST surface.
        'legacy_ajax'          => [
            // bcc-trust/app/Domain/Core/Services/UserLifecycleService.php
            'trust_sync_user',
            'trust_bulk_sync_users',
            'trust_init_page_score',
        ],
        // cron_dispatch — soft failures from wp_schedule_single_event /
        // AsyncDispatcher::enqueueAsync on the trust async surface. A `false`
        // return from wp-cron's options-table write (or AS returning 0)
        // means the worker never fires. Most post-mutation paths have a
        // reconciliation sweep that re-enqueues (DisputeScheduler::doReconcile
        // for panelist notify, the recurring graph/recalc/stats sweeps for
        // routine work) — the two events below are the surfaces where loss
        // is unrecoverable without manual replay:
        //   - endorsement_fraud_analyzer: per-endorsement fraud rescoring.
        //     No reconciliation; a missed enqueue means the endorser keeps
        //     a trust bonus they should not.
        //   - vote_job_dispatcher: the composite `bcc_trust_async_post_vote`
        //     job that fans out to fraud analysis + trust graph + recalc +
        //     stats refresh. A miss strands all four sub-tasks for that vote.
        // Sustained nonzero activation = wp_options is unhealthy on the
        // hot mutation path — read like an incident, not a warning.
        'cron_dispatch'        => [
            'endorsement_fraud_analyzer',
            'vote_job_dispatcher',
        ],
        // gated_group_provision — `bcc_gated_group_provision` cron
        // sweep failure modes. The sweep iterates verified collections
        // and creates a closed PeepSo group per unprovisioned one;
        // failed provisions are retried on the next tick (daily cadence),
        // so persistent activation here means the retry path is not
        // catching up. Three events cover the operationally distinct
        // failure modes:
        //   - peepso_absent: PeepSoGroup class missing entirely. Whole
        //     sweep short-circuits; daily activation = PeepSo plugin
        //     state is broken on the site (different from the per-
        //     writer `peepso_absence` subsystem because the failure
        //     surface is the SWEEP, not a writer call).
        //   - no_admin_owner: no administrator user exists to own
        //     auto-provisioned groups. Whole sweep short-circuits;
        //     persistent activation = administrative-account state
        //     is broken (rare; typically only after destructive ops).
        //   - group_create_failed: `new PeepSoGroup` returned a 0-id
        //     group OR its constructor threw. PER-COLLECTION event so
        //     the counter scales with the size of the failed batch;
        //     sustained activation = PeepSo Groups subsystem unhealthy
        //     even though the class loaded.
        'gated_group_provision' => [
            'peepso_absent',
            'no_admin_owner',
            'group_create_failed',
        ],
        // helius_dedup — Helius Solana webhook replay-protection
        // activations. Recorded inside HeliusWebhookEndpoint::handle
        // every time HeliusSeenSignaturesRepository::markSeen returns
        // false (signature already seen). The dedup itself is *working*
        // — the replay was correctly refused — but sustained nonzero
        // activation is operationally interesting:
        //   - Legitimate: Helius double-sent. Their dashboard often
        //     shows it; we just want it visible on /system/health too.
        //   - Hostile: attacker stole the auth header and is replaying
        //     known signatures (always-200 endpoint, so they can't
        //     distinguish dedup-skip from real ingest by response shape;
        //     this counter is how operators discover the attempt).
        // Distinct from the admin-panel `bcc_helius_signature_seen_total`
        // wp_options counter that drives the Helius admin tile —
        // /system/health is the canonical operator surface.
        'helius_dedup'         => ['replay_skipped'],
        // polkadot_verify — sr25519/ed25519/ecdsa signature verification
        // is delegated to the bcc-frontend Next.js app (PHP has no native
        // schnorrkel). Activations here mean the internal verify route
        // is unreachable, misconfigured, or returned an error response.
        // Sustained nonzero counts = Polkadot wallet linking is broken;
        // the Helius-style "always-200 + audit-log" posture does NOT
        // apply (this is an authenticated internal call, not a public
        // webhook). See PolkadotSignatureVerifier.
        'polkadot_verify'      => [
            'secret_missing',       // BCC_INTERNAL_VERIFY_SECRET not defined
            'frontend_url_missing', // BCC_FRONTEND_INTERNAL_URL not defined
            'route_unreachable',    // network/SSRF/transport error
            'route_error_status',   // non-200 response from the route
            'route_malformed_body', // response body wasn't the expected shape
        ],
    ]);

    return $health;
});

// ── System health endpoint ─────────────────────────────────────
// Aggregates operational health data from all BCC plugins into a
// single admin-only endpoint for monitoring, alerting, and debugging.

add_action('rest_api_init', function () {
    register_rest_route('bcc/v1', '/system/health', [
        'methods'             => \WP_REST_Server::READABLE,
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () {
            $now = time();

            // ── Redis / object cache status ─────────────────────────────
            $hasRedis = wp_using_ext_object_cache();
            $cacheTestKey = 'bcc_health_probe_' . $now;
            wp_cache_set($cacheTestKey, 1, 'bcc_health', 10);
            $cacheWritable = wp_cache_get($cacheTestKey, 'bcc_health') === 1;
            wp_cache_delete($cacheTestKey, 'bcc_health');

            // ── Rate-limit row count in wp_options ──────────────────────
            // Use range scan instead of LIKE wildcards to avoid full table scan.
            $rlRowCount1 = \BCC\Core\Repositories\DbMetricsRepository::countOptionsInRange(
                '_bcc_rl_',
                '_bcc_rl_~'
            );
            $rlRowCount2 = \BCC\Core\Repositories\DbMetricsRepository::countOptionsInRange(
                '_transient_bcc_rl_',
                '_transient_bcc_rl_~'
            );
            $rlRowCount  = $rlRowCount1 + $rlRowCount2;

            // ── Service availability ────────────────────────────────────
            $services = [];
            $contracts = [
                'TrustReadService'       => \BCC\Core\Contracts\TrustReadServiceInterface::class,
                'ScoreContributor'       => \BCC\Core\Contracts\ScoreContributorInterface::class,
                'ScoreReadService'       => \BCC\Core\Contracts\ScoreReadServiceInterface::class,
                'DisputeAdjudicator'     => \BCC\Core\Contracts\DisputeAdjudicationInterface::class,
                'PageOwnerResolver'      => \BCC\Core\Contracts\PageOwnerResolverInterface::class,
                'WalletLinkRead'         => \BCC\Core\Contracts\WalletLinkReadInterface::class,
                'OnchainDataRead'        => \BCC\Core\Contracts\OnchainDataReadInterface::class,
            ];
            foreach ($contracts as $label => $contract) {
                $services[$label] = \BCC\Core\ServiceLocator::hasRealService($contract);
            }

            // ── Read model lag (oldest dirty page age) ──────────────────
            $rmDirtySet = wp_cache_get('bcc_rm_dirty_pages', 'bcc_rm_sync');
            $rmDirtyCount = 0;
            $rmMaxAgeSec  = 0;
            if (is_array($rmDirtySet) && !empty($rmDirtySet)) {
                $rmDirtyCount = count($rmDirtySet);
                $oldestFlag   = min($rmDirtySet);
                $rmMaxAgeSec  = $now - (int) $oldestFlag;
            }

            // ── DB active connections / threads running ──────────────────
            // Use SHOW STATUS (works on MySQL 5.7 and 8.x).
            // information_schema.GLOBAL_STATUS was removed in MySQL 8.0.
            $dbThreadsConnected = \BCC\Core\Repositories\DbMetricsRepository::showGlobalStatusInt('Threads_connected');
            $dbThreadsRunning   = \BCC\Core\Repositories\DbMetricsRepository::showGlobalStatusInt('Threads_running');
            $dbMaxConnections   = \BCC\Core\Repositories\DbMetricsRepository::showSystemVariableInt('max_connections');

            // ── Recalculation queue depth ────────────────────────────────
            // Resolved via RecalcQueueReadInterface so bcc-core does not
            // reach into trust-engine tables. The `source` field
            // disambiguates "trust-engine answered" from "null object
            // because trust-engine is not wired". `pending_pages` is
            // null when the queue is unreachable (either the NullObject
            // is in use, or the real adapter's COUNT query failed) so
            // dashboards can alert on "unknown" distinctly from 0.
            $recalcQueueReal = \BCC\Core\ServiceLocator::hasRealService(
                \BCC\Core\Contracts\RecalcQueueReadInterface::class
            );
            $recalcPending = \BCC\Core\ServiceLocator::resolveRecalcQueueRead()->pendingCount();
            if ($recalcPending === null) {
                $recalcSource = $recalcQueueReal ? 'error' : 'unavailable';
            } else {
                $recalcSource = 'trust_engine';
            }

            // ── Plugin-specific health (pulled via filter) ──────────────
            $pluginHealth = apply_filters('bcc_system_health', []);

            // ── Trust subsystem readiness ────────────────────────────────
            // These checks detect the "plugin active but system inert" state
            // that occurs when BCC_ENCRYPTION_KEY is missing or the trust
            // engine fails to register its ServiceLocator providers.
            $trustSubsystem = [
                'encryption_key_defined'   => defined('BCC_ENCRYPTION_KEY'),
                'trust_read_service_real'  => $services['TrustReadService'] ?? false,
                'dispute_adjudicator_real' => $services['DisputeAdjudicator'] ?? false,
            ];

            // System is degraded if any critical trust subsystem check fails.
            // NullTrustReadService::isSuspended() returns true, which locks
            // out all non-admin users — this is a platform-wide outage.
            $trustHealthy = $trustSubsystem['encryption_key_defined']
                         && $trustSubsystem['trust_read_service_real'];

            $overallStatus = $trustHealthy ? 'ok' : 'degraded';

            $response = rest_ensure_response([
                'status'    => $overallStatus,
                'timestamp' => gmdate('c'),
                'cache'     => [
                    'persistent_object_cache' => $hasRedis,
                    'cache_writable'          => $cacheWritable,
                    'rate_limiter_degraded'   => \BCC\Core\Security\Throttle::isDegraded(),
                    'rate_limit_rows'         => $rlRowCount,
                ],
                'read_model' => [
                    'dirty_pages'      => $rmDirtyCount,
                    'max_age_seconds'  => $rmMaxAgeSec,
                ],
                'recalculation' => [
                    'pending_pages' => $recalcPending,
                    'source'        => $recalcSource,
                ],
                'database' => [
                    'threads_connected' => $dbThreadsConnected,
                    'threads_running'   => $dbThreadsRunning,
                    'max_connections'   => $dbMaxConnections,
                    'utilization_pct'   => $dbMaxConnections > 0
                        ? round(($dbThreadsConnected / $dbMaxConnections) * 100, 1)
                        : 0,
                ],
                'services'  => $services,
                'trust_subsystem' => $trustSubsystem,
                'wp_cron'   => [
                    'disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                ],
                'plugins'   => $pluginHealth,
            ]);
            $response->header('Cache-Control', 'private, max-age=60');
            return $response;
        },
    ]);

    // ── Public uptime probe ────────────────────────────────────────
    //
    // Returns HTTP 200 when the system is healthy, HTTP 503 when critical
    // subsystems are degraded. Safe to expose publicly — returns only a
    // minimal {status, checks} payload, no internal counts or DB state.
    //
    // Point an uptime monitor (UptimeRobot, Pingdom, Better Stack) at
    // /wp-json/bcc/v1/system/ping to get alerted when:
    //   - Trust read service is on NullObject fallback (platform down)
    //   - Dispute adjudicator is unavailable
    //   - Score recalculation cron is >15 minutes overdue
    //   - Read model dirty queue has been stuck >10 minutes
    //
    // Cached for 30s to absorb probe storms.
    register_rest_route('bcc/v1', '/system/ping', [
        'methods'             => \WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'callback'            => function () {
            // Serve from cache if fresh — absorbs monitoring traffic.
            $cached = wp_cache_get('ping_result', 'bcc_health');
            if (is_array($cached)) {
                $resp = rest_ensure_response($cached['body']);
                $resp->set_status($cached['http']);
                $resp->header('Cache-Control', 'public, max-age=30');
                return $resp;
            }

            $checks = [];
            $healthy = true;

            // ── Check 1: Trust read service is real (not NullObject) ─
            //
            // Cache-read-only probe (inspectReal) — NEVER call hasRealService()
            // from this public endpoint.  hasRealService() triggers a live
            // apply_filters() resolve on cache miss, which under probe storms
            // amplifies to per-provider registration work on every request.
            // An 'unknown' status (nothing resolved yet this request) is treated
            // as 'ok' for liveness purposes — cold-start probes should not be
            // false-positive-degraded.
            $trustReadStatus = \BCC\Core\ServiceLocator::inspectReal(
                \BCC\Core\Contracts\TrustReadServiceInterface::class
            );
            $checks['trust_read'] = ($trustReadStatus === 'null') ? 'fail' : 'ok';
            if ($trustReadStatus === 'null') $healthy = false;

            // ── Check 2: Dispute adjudicator is real ──────────────────
            $disputeStatus = \BCC\Core\ServiceLocator::inspectReal(
                \BCC\Core\Contracts\DisputeAdjudicationInterface::class
            );
            $checks['dispute_adjudicator'] = ($disputeStatus === 'null') ? 'fail' : 'ok';
            if ($disputeStatus === 'null') $healthy = false;

            // ── Check 3: Score recalc cron freshness ─────────────────
            // Cron writes option 'bcc_trust_last_recalc_run' after each
            // successful run. If older than 15 min, cron has stalled.
            $lastRecalc = (int) get_option('bcc_trust_last_recalc_run', 0);
            $recalcAge  = time() - $lastRecalc;
            if ($lastRecalc === 0) {
                // Grace: system may have just installed — don't fail.
                $checks['score_recalc_cron'] = 'pending';
            } elseif ($recalcAge > 900) {
                $checks['score_recalc_cron'] = 'stale';
                $healthy = false;
            } else {
                $checks['score_recalc_cron'] = 'ok';
            }

            // ── Check 4: Read model dirty queue not stuck ────────────
            $rmDirty = wp_cache_get('bcc_rm_dirty_pages', 'bcc_rm_sync');
            if (is_array($rmDirty) && !empty($rmDirty)) {
                $oldest = min($rmDirty);
                $age    = time() - (int) $oldest;
                if ($age > 600) {
                    $checks['read_model_sync'] = 'stuck';
                    $healthy = false;
                } else {
                    $checks['read_model_sync'] = 'ok';
                }
            } else {
                $checks['read_model_sync'] = 'ok';
            }

            // ── Check 5: Object cache writable ────────────────────────
            $probeKey = 'bcc_ping_probe_' . wp_generate_password(6, false);
            wp_cache_set($probeKey, 1, 'bcc_health', 10);
            $writable = wp_cache_get($probeKey, 'bcc_health') === 1;
            wp_cache_delete($probeKey, 'bcc_health');
            $checks['cache_writable'] = $writable ? 'ok' : 'fail';
            if (!$writable) $healthy = false;

            // Public ping — return ONLY the overall status. The per-check
            // breakdown (cron staleness, adjudicator availability, circuit
            // state) is a reconnaissance signal for attackers timing abuse
            // windows. Full details remain available to authenticated
            // admins via the /system/health endpoint, which uses
            // current_user_can('manage_options') as its permission_callback.
            $body = [
                'status'    => $healthy ? 'ok' : 'degraded',
                'timestamp' => gmdate('c'),
            ];
            $http = $healthy ? 200 : 503;

            // Cache the trimmed public payload only. The detailed $checks
            // array was intentionally dropped — do not re-include it here.
            wp_cache_set('ping_result', ['body' => $body, 'http' => $http], 'bcc_health', 30);

            $resp = rest_ensure_response($body);
            $resp->set_status($http);
            $resp->header('Cache-Control', 'public, max-age=30');
            return $resp;
        },
    ]);
});

