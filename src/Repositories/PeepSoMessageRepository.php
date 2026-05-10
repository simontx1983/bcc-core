<?php
/**
 * PeepSoMessageRepository — read-only access to PeepSo's DM graph
 * (peepso_message_participants + peepso_message_recipients + the
 * peepso-message CPT in wp_posts) for the §4.X messages surface.
 *
 * Single-graph rule: BCC reads from PeepSo's tables here and writes
 * exclusively through PeepSoMessageWriter. Never copy these rows
 * elsewhere — the canonical conversation graph stays in PeepSo.
 *
 * Bounded-query posture per §1–§5: every method has an explicit
 * column projection, prepared placeholders, and a hard `LIMIT` (or
 * a PK lookup). No SELECT *.
 *
 * @package BCC\Core\Repositories
 * @since v1.5 (2026-05, BCC messages adapter)
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ConversationListRow array{
 *     root_msg_id: int,
 *     last_msg_id: int,
 *     last_msg_author_id: int,
 *     last_msg_content: string,
 *     last_msg_posted_at: string,
 *     is_group: bool,
 *     last_activity: string|null
 * }
 * @phpstan-type MessageRow array{
 *     id: int,
 *     author_id: int,
 *     body: string,
 *     posted_at: string,
 *     post_type: string
 * }
 */
final class PeepSoMessageRepository
{
    /** Hard cap on the inbox-list IN()-clause when batching unread counts. */
    private const INBOX_BATCH_MAX = 100;

    /**
     * One row per conversation the viewer participates in (matches
     * PeepSoMessagesModel::get_messages's "latest message per parent"
     * shape) — paginated by `mpart_last_activity DESC` so the most
     * recently active conversation is page 0 entry 0.
     *
     * Bounded by `LIMIT $limit OFFSET $offset`, with `$limit` clamped
     * to 50 by the caller (REST endpoint args schema).
     *
     * @return list<array{
     *     root_msg_id: int,
     *     last_msg_id: int,
     *     last_msg_author_id: int,
     *     last_msg_content: string,
     *     last_msg_posted_at: string,
     *     is_group: bool,
     *     last_activity: string|null
     * }>
     */
    public static function findConversationsForUser(int $userId, int $limit, int $offset): array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }

        global $wpdb;

        // Outer query joins each viewer-visible parent conversation to
        // its most-recent message via a correlated MAX(mrec_msg_id).
        // Filters: viewer is a participant (mpart_msg_id), conversation
        // not deleted from viewer's inbox (mrec_deleted=0). Ordered by
        // the participant row's last_activity (PeepSo updates this on
        // every send via update_last_activity).
        $sql = $wpdb->prepare(
            "SELECT
                mpart.mpart_msg_id              AS root_msg_id,
                mpart.mpart_is_group            AS is_group,
                mpart.mpart_last_activity       AS last_activity,
                last_msg.ID                     AS last_msg_id,
                last_msg.post_author            AS last_msg_author_id,
                last_msg.post_content           AS last_msg_content,
                last_msg.post_date_gmt          AS last_msg_posted_at
             FROM {$wpdb->prefix}peepso_message_participants AS mpart
             INNER JOIN {$wpdb->posts} AS last_msg
                ON last_msg.ID = (
                    SELECT MAX(mrec.mrec_msg_id)
                    FROM {$wpdb->prefix}peepso_message_recipients AS mrec
                    INNER JOIN {$wpdb->posts} AS p
                        ON p.ID = mrec.mrec_msg_id
                    WHERE mrec.mrec_user_id = %d
                      AND mrec.mrec_deleted = 0
                      AND (mrec.mrec_parent_id = mpart.mpart_msg_id
                           OR mrec.mrec_msg_id = mpart.mpart_msg_id)
                      AND p.post_status = 'publish'
                      AND p.post_type IN ('peepso-message', 'peepso-message-notic')
                )
             WHERE mpart.mpart_user_id = %d
               AND last_msg.ID IS NOT NULL
             ORDER BY mpart.mpart_last_activity DESC, mpart.mpart_msg_id DESC
             LIMIT %d OFFSET %d",
            $userId,
            $userId,
            $limit,
            max(0, $offset)
        );

        /** @var list<array<string, string|null>> $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'root_msg_id'         => (int) ($row['root_msg_id'] ?? 0),
                'last_msg_id'         => (int) ($row['last_msg_id'] ?? 0),
                'last_msg_author_id'  => (int) ($row['last_msg_author_id'] ?? 0),
                'last_msg_content'    => (string) ($row['last_msg_content'] ?? ''),
                'last_msg_posted_at'  => (string) ($row['last_msg_posted_at'] ?? ''),
                'is_group'            => ((int) ($row['is_group'] ?? 0)) === 1,
                'last_activity'       => $row['last_activity'] !== null ? (string) $row['last_activity'] : null,
            ];
        }
        return $out;
    }

    /**
     * Total visible conversations for the viewer (offset pagination
     * total). One bounded COUNT — no LIMIT because COUNT itself
     * collapses the row count, and the participant count for a single
     * user is naturally bounded by their actual conversation count.
     */
    public static function countConversationsForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;

        // Mirror the WHERE of findConversationsForUser — only count
        // parents where the viewer has a non-deleted recipient row
        // somewhere in the conversation's history.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT mpart.mpart_msg_id)
             FROM {$wpdb->prefix}peepso_message_participants AS mpart
             WHERE mpart.mpart_user_id = %d
               AND EXISTS (
                   SELECT 1
                   FROM {$wpdb->prefix}peepso_message_recipients AS mrec
                   WHERE mrec.mrec_user_id = %d
                     AND mrec.mrec_deleted = 0
                     AND (mrec.mrec_parent_id = mpart.mpart_msg_id
                          OR mrec.mrec_msg_id = mpart.mpart_msg_id)
               )",
            $userId,
            $userId
        ));
    }

    /**
     * Batch-resolve unread-message counts for a list of conversation
     * roots. One GROUP BY scan keyed on `mrec_parent_id IN (...)` —
     * replaces N+1 per-row calls in the inbox view.
     *
     * Empty / unknown ids are absent from the map; callers default to 0.
     *
     * Bounded by INBOX_BATCH_MAX (100) which already exceeds the inbox
     * `per_page` ceiling (50).
     *
     * @param list<int> $rootMsgIds
     * @return array<int, int> root_msg_id => unread count
     */
    public static function getUnreadCountsByConversation(int $userId, array $rootMsgIds): array
    {
        if ($userId <= 0 || $rootMsgIds === []) {
            return [];
        }

        $clean = [];
        foreach ($rootMsgIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_slice(array_keys($clean), 0, self::INBOX_BATCH_MAX);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $args = $ids;
        array_unshift($args, $userId);

        /** @var list<array{mrec_parent_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT mrec_parent_id, COUNT(*) AS c
                 FROM {$wpdb->prefix}peepso_message_recipients
                 WHERE mrec_user_id = %d
                   AND mrec_viewed = 0
                   AND mrec_deleted = 0
                   AND mrec_parent_id IN ({$placeholders})
                 GROUP BY mrec_parent_id",
                ...$args
            ),
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['mrec_parent_id']] = (int) $row['c'];
        }
        return $map;
    }

    /**
     * Total unread DMs across all of the viewer's conversations — for
     * the global header badge. Wraps PeepSoMessageRecipients's existing
     * helper so we honor the same "exclude muted" + "exclude deleted-
     * user" filters PeepSo's notification surface uses.
     */
    public static function getUnreadConversationCountForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if (!class_exists('PeepSoMessageRecipients')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_repo_unread_count');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return 0;
        }
        $recipients = new \PeepSoMessageRecipients();
        return (int) $recipients->get_unread_messages_count($userId);
    }

    /**
     * Is the viewer a participant of the given conversation root? The
     * canonical authorization gate — every read or write against a
     * conversation MUST verify this first. Wraps PeepSo's primitive.
     */
    public static function userIsParticipant(int $userId, int $rootMsgId): bool
    {
        if ($userId <= 0 || $rootMsgId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoMessageParticipants')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_repo_is_participant');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }
        $part = new \PeepSoMessageParticipants();
        return (bool) $part->in_conversation($userId, $rootMsgId);
    }

    /**
     * Resolve the canonical root conversation id for any message id.
     * Mirrors PeepSoMessagesModel::get_root_conversation. Used to
     * normalize inbound `{id}` path params (the URL might carry a
     * specific message's id; we always store/key on the root).
     */
    public static function findRootConversationId(int $msgId): int
    {
        if ($msgId <= 0) {
            return 0;
        }
        if (!class_exists('PeepSoMessagesModel')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_repo_root_conversation_id');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return 0;
        }
        $model = new \PeepSoMessagesModel();
        return (int) $model->get_root_conversation($msgId);
    }

    /**
     * Participants of a conversation, optionally excluding users who
     * have a mutual block with `$viewerId` (PeepSo's get_participants
     * already wires this filter when `$viewerId` is non-null).
     *
     * Bounded — a conversation's participant count is naturally small;
     * no explicit LIMIT but we cap at 200 defensively.
     *
     * @return list<int>
     */
    public static function getParticipantUserIds(int $rootMsgId, ?int $viewerId = null): array
    {
        if ($rootMsgId <= 0) {
            return [];
        }
        if (!class_exists('PeepSoMessageParticipants')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_repo_participants');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return [];
        }
        $part = new \PeepSoMessageParticipants();
        $rows = $part->get_participants($rootMsgId, $viewerId);

        $out = [];
        foreach ($rows ?: [] as $row) {
            $u = (int) $row;
            if ($u > 0) {
                $out[] = $u;
            }
            if (count($out) >= 200) {
                break;
            }
        }
        return $out;
    }

    /**
     * Paginated message history for a conversation. Returns rows in
     * `posted_at ASC` order (oldest first within the page) for chat-
     * style rendering, but `offset` walks BACKWARD through history —
     * i.e., offset=0 returns the most-recent `$limit` messages,
     * offset=$limit returns the next-older window. Caller paginates
     * by incrementing offset.
     *
     * Implementation: we compute the slice via a sub-query that
     * selects msg ids by mrec_msg_id DESC + LIMIT/OFFSET (the same
     * shape PeepSoMessagesModel::get_messages_in_conversation uses),
     * then fetch the matching wp_posts rows in ASC order.
     *
     * @return list<array{
     *     id: int,
     *     author_id: int,
     *     body: string,
     *     posted_at: string,
     *     post_type: string
     * }>
     */
    public static function findMessagesInConversation(
        int $rootMsgId,
        int $userId,
        int $limit,
        int $offset
    ): array {
        if ($rootMsgId <= 0 || $userId <= 0 || $limit <= 0) {
            return [];
        }

        global $wpdb;

        // Step 1: page of mrec_msg_ids from this user's recipient rows
        // (only this user's rows so a delete-from-inbox respects per-
        // user state). Newest-first selection means OFFSET walks
        // backwards in history.
        $idSql = $wpdb->prepare(
            "SELECT mrec_msg_id
             FROM {$wpdb->prefix}peepso_message_recipients
             WHERE (mrec_parent_id = %d OR mrec_msg_id = %d)
               AND mrec_user_id = %d
             ORDER BY mrec_msg_id DESC
             LIMIT %d OFFSET %d",
            $rootMsgId,
            $rootMsgId,
            $userId,
            $limit,
            max(0, $offset)
        );
        /** @var list<string> $idRows */
        $idRows = $wpdb->get_col($idSql) ?: [];
        if ($idRows === []) {
            return [];
        }
        $ids = array_map('intval', $idRows);

        // Step 2: fetch the matching wp_posts rows ordered ASC for
        // chat-style rendering on the page. IN() is bounded by `$limit`.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $postSql = $wpdb->prepare(
            "SELECT
                ID                              AS id,
                post_author                     AS author_id,
                post_content                    AS body,
                post_date_gmt                   AS posted_at,
                post_type                       AS post_type
             FROM {$wpdb->posts}
             WHERE ID IN ({$placeholders})
               AND post_type IN ('peepso-message', 'peepso-message-notic')
               AND post_status = 'publish'
             ORDER BY post_date_gmt ASC, ID ASC",
            ...$ids
        );

        /** @var list<array<string, string|null>> $rows */
        $rows = $wpdb->get_results($postSql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'author_id'  => (int) ($row['author_id'] ?? 0),
                'body'       => (string) ($row['body'] ?? ''),
                'posted_at'  => (string) ($row['posted_at'] ?? ''),
                'post_type'  => (string) ($row['post_type'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Mark every unread message in `$rootMsgId` as viewed for `$userId`.
     * Wraps PeepSoMessageRecipients::mark_as_viewed (which internally
     * walks the conversation tree via mrec_parent_id).
     */
    public static function markConversationAsViewed(int $userId, int $rootMsgId): void
    {
        if ($userId <= 0 || $rootMsgId <= 0) {
            return;
        }
        if (!class_exists('PeepSoMessageRecipients')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_repo_mark_viewed');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return;
        }
        $recipients = new \PeepSoMessageRecipients();
        $recipients->mark_as_viewed($userId, $rootMsgId, true);
    }

    /**
     * Count messages authored by `$authorId` in the last
     * `$windowSeconds` — drives the §4.X.send rate limit. Bounded:
     * the post_date index keeps this a range scan, never a full
     * table read.
     */
    public static function countRecentByAuthor(int $authorId, int $windowSeconds): int
    {
        if ($authorId <= 0 || $windowSeconds <= 0) {
            return 0;
        }
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_author = %d
               AND post_type = 'peepso-message'
               AND post_status = 'publish'
               AND post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $authorId,
            $windowSeconds
        ));
    }
}
