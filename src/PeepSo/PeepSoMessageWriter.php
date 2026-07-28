<?php
/**
 * PeepSoMessageWriter — thin wrapper around PeepSoMessagesModel for
 * starting / appending to direct-message conversations.
 *
 * Sibling of PeepSoCommentWriter / PeepSoReactionWriter / PeepSoStatusWriter.
 * Single-graph rule: BCC must NEVER INSERT directly into
 *   - peepso_message_participants
 *   - peepso_message_recipients
 *   - wp_posts (post_type = peepso-message)
 *   - peepso_activities (the message's activity row)
 * because PeepSoMessagesModel + PeepSoMessageParticipants + PeepSoMessageRecipients
 * own:
 *   - the wp_post insert with the right post_type
 *   - the activity-stream row + `peepso_activity_after_add_post` action
 *   - per-participant recipient-row fan-out
 *   - mark-as-viewed for the author's own row
 *   - email + SSE notification fan-out via PeepSoMailQueue
 *   - "find existing 1-on-1 conversation" idempotency
 *
 * Bypassing the model would silently break all of those concerns, so
 * this writer is the only allowed write path from BCC.
 *
 * Contract:
 *   - sendNewMessage(int $authorId, int $recipientId, string $body): array{conversation_id:int, message_id:int, is_new_conversation:bool} | null
 *       Starts a 1-on-1 conversation with `$recipientId` if none exists,
 *       or appends to the existing 1-on-1 conversation between the two
 *       users. Either way returns the conversation root id + the new
 *       message id. Returns null on failure.
 *
 *   - sendInConversation(int $authorId, int $rootMsgId, string $body): int
 *       Appends a message to an existing conversation. Returns the new
 *       message's wp_posts.ID, or 0 on failure. Caller MUST verify
 *       `$authorId` is a participant of `$rootMsgId` BEFORE calling —
 *       this writer does NOT enforce participation.
 *
 * Returns null / 0 on:
 *   - PeepSoMessagesModel class missing (peepso-messages plugin
 *     deactivated)
 *   - invalid IDs (zero or negative)
 *   - empty body after trim
 *   - PeepSoMessagesModel returning WP_Error or FALSE
 *
 * Caller responsibilities (NOT enforced here — see MessagesService):
 *   - `peepso_chat_enabled` gate on both sender and recipient
 *   - `peepso_chat_friends_only` + PeepSoFriendsModel::are_friends gate
 *   - PeepSoBlockRepository::isMutuallyBlocked
 *   - rate limit (per-sender burst seatbelt)
 *   - length cap on `$body`
 *
 * @package BCC\Core\PeepSo
 * @since v1.5 (2026-05, BCC messages adapter)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoMessageWriter
{
    /**
     * True only in an execution context that can actually create PeepSo
     * conversations — i.e. one where PeepSo has booted and its Chat
     * module + user classes are loaded.
     *
     * This is the integration boundary's own readiness/capability check,
     * owned here so background jobs (the validator-message delivery
     * worker) and CLI commands don't accumulate PeepSo class knowledge.
     * It exists to DISTINGUISH two very different situations that both
     * otherwise surface as `sendNewMessage() === null`:
     *
     *   1. "unsupported context" — PeepSo is not initialised at all, so
     *      the writer CANNOT run here. This is deterministic for the
     *      context, not a per-message fault. The canonical case is
     *      **WP-CLI**: PeepSo deliberately bails when
     *      `php_sapi_name() === 'cli' && defined('WP_CLI') && WP_CLI`
     *      (PeepSo::ready() returns null), so its Chat module never
     *      registers `PeepSoMessagesModel`'s autoload directory and the
     *      class never resolves. `wp cron event run` / `wp
     *      action-scheduler run` / `wp bcc-trust vmq drain` all hit this.
     *   2. "writer available but the send failed" — PeepSo IS booted and
     *      the model exists, but create_new_conversation returned false
     *      (a genuine, retryable delivery failure).
     *
     * Callers use this to fail LOUD and recover-safe in case (1) instead
     * of silently consuming retry attempts. Both `PeepSoMessagesModel`
     * (the writer) and `PeepSoUser` (needed by senders' ban re-check)
     * come from the same PeepSo init, so both are asserted: readiness
     * means the full delivery + gate path can run, not just the insert.
     * class_exists() autoload is safe — a missing autoload directory
     * (the CLI case) simply returns false with no side effect, and in a
     * booted context it only defines a plain model/user class.
     */
    public static function isReady(): bool
    {
        return class_exists('PeepSoMessagesModel') && class_exists('PeepSoUser');
    }

    /**
     * Start a new 1-on-1 conversation OR append to an existing one
     * between `$authorId` and `$recipientId`. PeepSoMessagesModel's
     * `create_new_conversation` handles the find-or-create inline:
     * if a 2-participant conversation already exists between the
     * pair, it adds the message there; otherwise it creates a fresh
     * one. We surface which path ran via `is_new_conversation`.
     *
     * @return array{conversation_id:int, message_id:int, is_new_conversation:bool}|null
     */
    public static function sendNewMessage(int $authorId, int $recipientId, string $body): ?array
    {
        $body = trim($body);
        if ($authorId <= 0 || $recipientId <= 0 || $authorId === $recipientId || $body === '') {
            return null;
        }
        if (!class_exists('PeepSoMessagesModel')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_writer_send_new');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return null;
        }

        $model = new \PeepSoMessagesModel();

        // Detect whether a 1-on-1 conversation already exists BEFORE
        // calling create_new_conversation (which would insert into the
        // existing convo silently). Lets us tell the frontend whether
        // to navigate or stay on the new-message UI.
        $existingRoot = $model->get_conversation_between($authorId, $recipientId);
        $isExisting   = $existingRoot !== null && (int) $existingRoot > 0;

        $result = $model->create_new_conversation(
            $authorId,
            $body,
            '', // post_title — empty by convention (PeepSo uses post_content)
            [$recipientId] // participants array; create_new_conversation auto-adds the creator
        );

        if (is_wp_error($result) || $result === false) {
            return null;
        }

        $messageId = (int) $result;
        if ($messageId <= 0) {
            return null;
        }

        // When `create_new_conversation` ran the find-existing branch,
        // the returned id IS the new message id (not the root) and the
        // root was the pre-existing conversation. When it created
        // fresh, the returned id is also the message id but the
        // conversation root equals the message id (root = first
        // message). Resolve via the model's own helper so we always
        // surface the canonical root.
        $rootId = (int) $model->get_root_conversation($messageId);
        if ($rootId <= 0) {
            $rootId = $messageId;
        }

        return [
            'conversation_id'      => $rootId,
            'message_id'           => $messageId,
            'is_new_conversation'  => !$isExisting,
        ];
    }

    /**
     * Append a message to an existing conversation by its root id.
     * Caller MUST verify the author is a participant of the
     * conversation BEFORE calling — see MessagesService for the gate.
     *
     * @return int wp_posts.ID of the new message, 0 on failure.
     */
    public static function sendInConversation(int $authorId, int $rootMsgId, string $body): int
    {
        $body = trim($body);
        if ($authorId <= 0 || $rootMsgId <= 0 || $body === '') {
            return 0;
        }
        if (!class_exists('PeepSoMessagesModel')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'message_writer_send_in_conversation');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return 0;
        }

        $model = new \PeepSoMessagesModel();
        $result = $model->add_to_conversation($authorId, $rootMsgId, $body);

        if ($result === false || !is_int($result)) {
            return 0;
        }
        return $result > 0 ? $result : 0;
    }
}
