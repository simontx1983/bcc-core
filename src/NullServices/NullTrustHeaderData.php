<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrustHeaderDataInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 *
 * Returns a default data structure with neutral/empty values so the
 * trust header template renders without errors.
 */
final class NullTrustHeaderData implements TrustHeaderDataInterface
{
    public function getTrustHeaderData(int $pageId, string $mode = 'public'): array
    {
        return [
            'page_id'          => $pageId,
            'mode'             => $mode,
            'total'            => 50,
            'confidence'       => 0,
            'endorsements'     => 0,
            'grade'            => 'N/A',
            'votes_up'         => 0,
            'votes_down'       => 0,
            'unique_voters'    => 0,
            'viewer_vote'      => 0,
            'viewer_endorsed'  => false,
            'show_interactive' => false,
            'context_label'    => '',
            'page_name'        => '',
            'logged_in'        => is_user_logged_in(),
        ];
    }
}
