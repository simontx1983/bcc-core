<?php

namespace BCC\Core\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralised table-name resolver.
 *
 * All BCC plugins should use `DB::table('disputes')` instead of
 * hard-coding `$wpdb->prefix . 'bcc_disputes'` everywhere.
 */
final class DB
{
    /**
     * Known table name → function mapping.
     *
     * If the corresponding bcc-trust-engine helper exists it is used
     * (it may apply custom prefixes); otherwise we fall back to the
     * standard `{wp_prefix}bcc_{$name}` pattern.
     */
    private const TRUST_ENGINE_HELPERS = [
        'trust_votes'              => 'bcc_trust_votes_table',
        'trust_page_scores'        => 'bcc_trust_scores_table',
        'trust_endorsements'       => 'bcc_trust_endorsements_table',
        'trust_verifications'      => 'bcc_trust_verifications_table',
        'trust_eligibility'        => 'bcc_trust_eligibility_table',
        'trust_activity'           => 'bcc_trust_activity_table',
        'trust_activity_archive'   => 'bcc_trust_activity_archive_table',
        'trust_flags'              => 'bcc_trust_flags_table',
        'trust_reputation'         => 'bcc_trust_reputation_table',
        'trust_fingerprints'       => 'bcc_trust_fingerprints_table',
        'trust_patterns'           => 'bcc_trust_patterns_table',
        'trust_user_info'          => 'bcc_trust_user_info_table',
        'trust_fraud_analysis'     => 'bcc_trust_fraud_analysis_table',
        'trust_suspensions'        => 'bcc_trust_suspensions_table',
        'trust_user_verifications' => 'bcc_trust_user_verifications_table',
        'trust_user_risk'          => 'bcc_trust_user_risk_table',
        'trust_edges'              => 'bcc_trust_edges_table',
        'trust_page_composites'    => 'bcc_trust_page_composites_table',
        'trust_page_verifications' => 'bcc_trust_page_verifications_table',
        'trust_page_metrics'       => 'bcc_trust_page_metrics_table',
        'trust_page_identities'    => 'bcc_trust_page_identities_table',
        'trust_endorsement_types'  => 'bcc_trust_endorsement_types_table',
    ];

    /**
     * Resolve a fully-qualified table name.
     *
     * @param string $name  Short name, e.g. 'disputes', 'trust_votes'.
     * @return string  Full table name including WP prefix.
     */
    public static function table(string $name): string
    {
        // Check for a trust-engine helper first.
        if (isset(self::TRUST_ENGINE_HELPERS[$name])) {
            $fn = self::TRUST_ENGINE_HELPERS[$name];
            if (function_exists($fn)) {
                return $fn();
            }
        }

        global $wpdb;
        return $wpdb->prefix . 'bcc_' . $name;
    }
}
