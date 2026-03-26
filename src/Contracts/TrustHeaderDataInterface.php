<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only contract for retrieving trust header display data.
 *
 * The trust engine owns all score, vote, endorsement, and verification
 * data.  Consumer plugins (e.g. bcc-peepso-integration) call this
 * interface to obtain a pre-composed data array for rendering the
 * trust header — they never query trust tables directly.
 */
interface TrustHeaderDataInterface
{
    /**
     * Return all data needed to render the trust header for a page.
     *
     * @param int    $pageId  PeepSo Page ID.
     * @param string $mode    Display context: 'public' or 'dashboard'.
     *
     * @return array{
     *     page_id: int,
     *     mode: string,
     *     total: int,
     *     confidence: int,
     *     endorsements: int,
     *     grade: string,
     *     votes_up: int,
     *     votes_down: int,
     *     unique_voters: int,
     *     viewer_vote: int,
     *     viewer_endorsed: bool,
     *     show_interactive: bool,
     *     context_label: string,
     *     page_name: string,
     *     logged_in: bool,
     * }
     */
    public function getTrustHeaderData(int $pageId, string $mode = 'public'): array;
}
