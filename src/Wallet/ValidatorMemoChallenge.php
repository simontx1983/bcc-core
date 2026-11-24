<?php
/**
 * The validator memo claim's challenge (design §4.1, §4.2; A2).
 *
 * A signed-in member asks to claim a validator page without connecting a
 * wallet. The server issues a challenge carrying the exact memo the
 * operator must send, from the operator account to itself:
 *
 *   BCC: claim validator page <valoper> on <host>. Code <code>
 *
 * - NO member is named on chain (Phillip's ruling, 2026-11-24): the memo
 *   carries the purpose, the valoper, the host and the code only. Which
 *   member it is for lives server-side, on the challenge (its user id).
 * - The code is 8 symbols from a CSPRNG over an alphabet without the
 *   look-alikes 0 O 1 I (and no lowercase, so no l): 40 bits, used once,
 *   and UNIQUE among live challenges (a collision draws again).
 * - The memo is printable ASCII, at most 256 bytes (the Cosmos SDK's
 *   default memo limit), and compared byte for byte (memoMatches()).
 * - A challenge is LIVE for 30 minutes, which bounds INCLUSION: the
 *   block time must fall in [issued − 60 s, issued + 30 min]. It is kept
 *   for 30 minutes more so a submitted hash can finish verifying.
 * - The submitted hash is RESERVED on the challenge (reserve()): one hash
 *   per challenge.
 * - The claim consumes it, inside the claim's own transaction
 *   (consumeInTransaction()).
 * - Only its own member may reserve or consume it. A withdrawal or a newer
 *   challenge on the same valoper makes it unusable.
 * - findLiveByCode(): which challenge a (valoper, code) pair names, while it
 *   is live and unreserved. For the operator-facing lookup (design A5) that
 *   shows who a code links the validator to.
 *
 * Limits, security's ruling of 2026-11-24, all named constants below:
 *   - per member: 3 live, 5 issued per hour, and ONE live challenge per
 *     (member, valoper). Re-issuing for the same valoper replaces only the
 *     member's own earlier one, unless a hash was already submitted for it.
 *   - per valoper: no refusal at normal levels (a challenge grants nothing,
 *     and the unique code stops one member using another's). When 5 or
 *     more members hold live challenges on one valoper, the metric
 *     `validator_memo_claim/crowded_valoper` is recorded and a warning
 *     logged: it looks like a phishing campaign against that operator.
 *     A backstop refuses new members past 50 live (`valoper_backstop`),
 *     and a member's own challenge is always renewable. Nothing is evicted.
 *
 * Storage: ChallengeRepository (wp_options rows), one row per challenge
 * plus a member index and a valoper index. Issuing and withdrawing hold
 * one advisory lock, so the caps are read and written by one writer.
 *
 * @package BCC\Core\Wallet
 */

namespace BCC\Core\Wallet;

use BCC\Core\Crypto\Bech32;
use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\Support\FrontendOriginConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorMemoChallenge
{
    public const PURPOSE = 'validator_memo_claim';

    /** 32 symbols, so each is exactly 5 bits: digits and capitals without 0, O, 1, I. */
    public const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const CODE_LENGTH   = 8;

    /** Seconds a challenge is live: the block time must fall inside it. */
    public const LIFETIME = 1800;
    /** A block may be this many seconds older than the challenge (clock skew). */
    public const CLOCK_SKEW = 60;
    /** Seconds kept after the window, so a submitted hash can finish verifying. */
    public const VERIFY_GRACE = 1800;

    /** The Cosmos SDK's default MaxMemoCharacters. */
    public const MEMO_MAX_BYTES = 256;

    // ── limits (security's ruling, 2026-11-24) ──────────────────────────
    public const MAX_LIVE_PER_MEMBER            = 3;
    public const MAX_ISSUED_PER_MEMBER_PER_HOUR = 5;
    /** Members with live challenges on one valoper at which the crowding signal fires. */
    public const CROWDED_VALOPER_MEMBERS = 5;
    /** The denial-of-service backstop: live challenges on one valoper. */
    public const MAX_LIVE_PER_VALOPER = 50;

    // ── issue() refusals ────────────────────────────────────────────────
    public const REFUSED_BAD_INPUT        = 'bad_input';
    public const REFUSED_NO_HOST          = 'no_public_host';
    public const REFUSED_IN_VERIFICATION  = 'in_verification';
    public const REFUSED_MEMBER_LIVE      = 'member_live_cap';
    public const REFUSED_MEMBER_RATE      = 'member_hourly_cap';
    public const REFUSED_VALOPER_BACKSTOP = 'valoper_backstop';
    public const REFUSED_BUSY             = 'busy';

    private const KEY_PREFIX    = 'bcc_vmc_';
    private const MEMBER_INDEX  = 'bcc_vmc_iu_';
    private const VALOPER_INDEX = 'bcc_vmc_iv_';
    /** code => the challenge holding it, while that challenge is kept. */
    private const CODE_INDEX    = 'bcc_vmc_code_';
    /** Draws of a code before issue() gives up on a free one. */
    private const CODE_DRAWS    = 5;
    private const ISSUE_LOCK    = 'bcc_vmc_issue';
    private const LOCK_WAIT     = 2;
    private const HOUR          = 3600;

    // ── the memo ────────────────────────────────────────────────────────

    /** A fresh code: CODE_LENGTH symbols of CODE_ALPHABET, each uniform from the CSPRNG. */
    public static function code(): string
    {
        $out = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $out;
    }

    public static function isCode(string $code): bool
    {
        return strlen($code) === self::CODE_LENGTH && strspn($code, self::CODE_ALPHABET) === self::CODE_LENGTH;
    }

    /** The valoper in canonical form (strict bech32, lowercase, 20 bytes), or null. */
    public static function canonicalValoper(string $valoper): ?string
    {
        $decoded = Bech32::decode($valoper);
        if ($decoded === null || strlen($decoded['bytes']) !== 20) {
            return null;
        }
        return Bech32::encode($decoded['hrp'], $decoded['bytes']);
    }

    /**
     * The memo for these parts, or null when any part is not of its form
     * or the memo would be over MEMO_MAX_BYTES.
     */
    public static function memo(string $valoper, string $host, string $code): ?string
    {
        $valoper = self::canonicalValoper($valoper);
        if ($valoper === null
            || preg_match('/\A[a-z0-9.-]+(:[0-9]{1,5})?\z/', $host) !== 1
            || !self::isCode($code)
        ) {
            return null;
        }
        $memo = 'BCC: claim validator page ' . $valoper . ' on ' . $host . '. Code ' . $code;
        return strlen($memo) <= self::MEMO_MAX_BYTES ? $memo : null;
    }

    /**
     * Is $txMemo (from a VERIFIED transaction) the issued memo? Byte for
     * byte after trimming outer whitespace from the transaction's memo
     * only. No case folding, no Unicode normalisation.
     */
    public static function memoMatches(string $issuedMemo, string $txMemo): bool
    {
        return $issuedMemo !== '' && hash_equals($issuedMemo, trim($txMemo, " \t\n\r\0\x0B"));
    }

    /**
     * Was a block at $blockTime (unix seconds) inside this challenge's
     * window: [issued − CLOCK_SKEW, issued + LIFETIME]?
     *
     * @param array<string, mixed> $challenge
     */
    public static function blockTimeInWindow(array $challenge, int $blockTime): bool
    {
        $issued = (int) ($challenge['issued_at'] ?? 0);
        return $issued > 0
            && $blockTime >= $issued - self::CLOCK_SKEW
            && $blockTime <= $issued + self::LIFETIME;
    }

    // ── the challenge ───────────────────────────────────────────────────

    /**
     * Issue a challenge to member $userId for the validator $valoper.
     *
     * @return array{ok: bool, reason: string, challenge: array<string, mixed>|null, existing_id: string}
     *         `existing_id`: on REFUSED_IN_VERIFICATION, the member's challenge
     *         for this valoper whose hash is being verified.
     */
    public static function issue(int $userId, string $valoper): array
    {
        $valoper = self::canonicalValoper($valoper);
        if ($userId <= 0 || $valoper === null) {
            return self::refused(self::REFUSED_BAD_INPUT);
        }
        $host = FrontendOriginConfig::publicHost();
        if (self::memo($valoper, $host, str_repeat(self::CODE_ALPHABET[0], self::CODE_LENGTH)) === null) {
            return self::refused($host === '' ? self::REFUSED_NO_HOST : self::REFUSED_BAD_INPUT);
        }

        if (!AdvisoryLock::acquire(self::ISSUE_LOCK, self::LOCK_WAIT)) {
            return self::refused(self::REFUSED_BUSY);
        }
        try {
            $now        = time();
            $memberKey  = self::MEMBER_INDEX . $userId;
            $valoperKey = self::VALOPER_INDEX . self::valoperDigest($valoper);
            $member     = self::readIndex($memberKey, $now);
            $onValoper  = self::readIndex($valoperKey, $now);

            // The member's own challenge on this valoper: replaced, unless a
            // hash was submitted for it and is still being verified.
            $own = null;
            foreach ($member as $id => $entry) {
                if (($entry['valoper'] ?? '') === $valoper && (int) ($entry['expires_at'] ?? 0) >= $now) {
                    $own = (string) $id;
                }
            }
            if ($own !== null) {
                $existing = ChallengeRepository::peek(self::KEY_PREFIX . $own);
                if ($existing !== null
                    && (string) ($existing['reserved_hash'] ?? '') !== ''
                    && (int) ($existing['withdrawn_at'] ?? 0) === 0
                ) {
                    return self::refused(self::REFUSED_IN_VERIFICATION, $own);
                }
            }

            // The limits. The challenge being replaced does not count.
            if (self::liveCount($member, $now, $own) >= self::MAX_LIVE_PER_MEMBER) {
                return self::refused(self::REFUSED_MEMBER_LIVE);
            }
            $issuedThisHour = 0;
            foreach ($member as $entry) {
                if ((int) ($entry['issued_at'] ?? 0) > $now - self::HOUR) {
                    $issuedThisHour++;
                }
            }
            if ($issuedThisHour >= self::MAX_ISSUED_PER_MEMBER_PER_HOUR) {
                return self::refused(self::REFUSED_MEMBER_RATE);
            }
            if ($own === null && self::liveCount($onValoper, $now, null) >= self::MAX_LIVE_PER_VALOPER) {
                DegradationMetrics::record('validator_memo_claim', 'valoper_backstop');
                return self::refused(self::REFUSED_VALOPER_BACKSTOP);
            }

            // A code no live challenge holds, drawn BEFORE anything changes.
            // 40 bits make a collision vanishingly rare: a few draws, then
            // refuse rather than reuse one.
            $code = null;
            for ($draw = 0; $draw < self::CODE_DRAWS && $code === null; $draw++) {
                $candidate = self::code();
                if (ChallengeRepository::peek(self::CODE_INDEX . $candidate) === null) {
                    $code = $candidate;
                }
            }
            if ($code === null) {
                return self::refused(self::REFUSED_BUSY);
            }
            $memo = (string) self::memo($valoper, $host, $code);

            if ($own !== null) {
                // Closed under its lock, or not replaced. A hash reserved
                // since the look above keeps it (in_verification); a write
                // that did not land refuses (busy). Only a challenge already
                // gone leaves nothing to replace.
                if (!self::endWindow($own, $now)) {
                    $still = ChallengeRepository::peek(self::KEY_PREFIX . $own);
                    if ($still !== null && (string) ($still['reserved_hash'] ?? '') !== '') {
                        return self::refused(self::REFUSED_IN_VERIFICATION, $own);
                    }
                    if ($still !== null) {
                        return self::refused(self::REFUSED_BUSY);
                    }
                }
                $member[$own]['window_ends_at'] = $now - 1;
                $member[$own]['expires_at']     = $now - 1;
                unset($onValoper[$own]);
            }

            $id        = bin2hex(random_bytes(12));
            $windowEnd = $now + self::LIFETIME;
            $expires   = $windowEnd + self::VERIFY_GRACE;
            $challenge = [
                'purpose'        => self::PURPOSE,
                'id'             => $id,
                'user_id'        => $userId,
                'valoper'        => $valoper,
                'code'           => $code,
                'memo'           => $memo,
                'issued_at'      => $now,
                'window_ends_at' => $windowEnd,
                'expires_at'     => $expires,
                'reserved_hash'  => '',
                'reserved_at'    => 0,
                'withdrawn_at'   => 0,
            ];
            ChallengeRepository::store(self::KEY_PREFIX . $id, $challenge, $expires - $now);
            // The code stays taken for as long as the challenge is kept.
            ChallengeRepository::store(self::CODE_INDEX . $code, ['id' => $id, 'expires_at' => $expires], $expires - $now);

            $member[$id]    = ['valoper' => $valoper, 'issued_at' => $now, 'window_ends_at' => $windowEnd, 'expires_at' => $expires];
            $onValoper[$id] = ['user_id' => $userId, 'issued_at' => $now, 'window_ends_at' => $windowEnd, 'expires_at' => $expires];
            self::writeIndex($memberKey, $member, $now);
            self::writeIndex($valoperKey, $onValoper, $now);

            // One live challenge per (member, valoper), so the live
            // challenges on a valoper ARE its distinct members.
            $members = self::liveCount($onValoper, $now, null);
            if ($members >= self::CROWDED_VALOPER_MEMBERS) {
                DegradationMetrics::record('validator_memo_claim', 'crowded_valoper');
                Logger::warning('[ValidatorMemoChallenge] many members hold live claim challenges on one validator', [
                    'valoper' => $valoper,
                    'members' => $members,
                ]);
            }

            return ['ok' => true, 'reason' => '', 'challenge' => $challenge, 'existing_id' => ''];
        } finally {
            AdvisoryLock::release(self::ISSUE_LOCK);
        }
    }

    /**
     * A stored challenge, or null (missing, expired, or not a challenge id).
     *
     * @return array<string, mixed>|null
     */
    public static function peek(string $id): ?array
    {
        if (!self::isId($id)) {
            return null;
        }
        $challenge = ChallengeRepository::peek(self::KEY_PREFIX . $id);
        return $challenge !== null && ($challenge['purpose'] ?? '') === self::PURPOSE ? $challenge : null;
    }

    /**
     * Reserve $txHash on the member's challenge: the hash the member
     * submitted. One hash per challenge: the same hash again is accepted,
     * any other is refused. Allowed until the challenge expires (the
     * window bounds the BLOCK time, which the caller checks, not when the
     * hash is pasted). Refused when the challenge is another member's, or
     * was withdrawn or replaced.
     *
     * @return array<string, mixed>|null the reserved challenge
     */
    public static function reserve(string $id, int $userId, string $txHash): ?array
    {
        $hash = self::normalizeHash($txHash);
        if (!self::isId($id) || $userId <= 0 || $hash === null) {
            return null;
        }
        return ChallengeRepository::update(
            self::KEY_PREFIX . $id,
            static function (array $challenge) use ($userId, $hash): ?array {
                if (!self::usableBy($challenge, $userId)) {
                    return null;
                }
                $reserved = (string) ($challenge['reserved_hash'] ?? '');
                if ($reserved !== '') {
                    return hash_equals($reserved, $hash) ? $challenge : null;
                }
                $challenge['reserved_hash'] = $hash;
                $challenge['reserved_at']   = time();
                return $challenge;
            }
        );
    }

    /**
     * The member withdraws their challenge: it can no longer be reserved or
     * consumed, and stops counting toward the live limits. False when it
     * is not theirs or is already gone.
     */
    public static function withdraw(string $id, int $userId): bool
    {
        if (!self::isId($id) || $userId <= 0) {
            return false;
        }
        $now     = time();
        $changed = ChallengeRepository::update(
            self::KEY_PREFIX . $id,
            static function (array $challenge) use ($userId, $now): ?array {
                if ((int) ($challenge['user_id'] ?? 0) !== $userId || (int) ($challenge['withdrawn_at'] ?? 0) !== 0) {
                    return null;
                }
                $challenge['withdrawn_at'] = $now;
                return $challenge;
            }
        );
        if ($changed === null) {
            return false;
        }

        // Stop it counting. Without the lock the entries simply count
        // until their window ends, which errs toward the limit.
        if (AdvisoryLock::acquire(self::ISSUE_LOCK, self::LOCK_WAIT)) {
            try {
                $memberKey = self::MEMBER_INDEX . $userId;
                $member    = self::readIndex($memberKey, $now);
                if (isset($member[$id])) {
                    $member[$id]['window_ends_at'] = $now - 1;
                    $member[$id]['expires_at']     = $now - 1;
                    self::writeIndex($memberKey, $member, $now);
                }
                $valoperKey = self::VALOPER_INDEX . self::valoperDigest((string) ($changed['valoper'] ?? ''));
                $onValoper  = self::readIndex($valoperKey, $now);
                if (isset($onValoper[$id])) {
                    unset($onValoper[$id]);
                    self::writeIndex($valoperKey, $onValoper, $now);
                }
            } finally {
                AdvisoryLock::release(self::ISSUE_LOCK);
            }
        }
        return true;
    }

    /**
     * Take the member's challenge INSIDE the claim's own transaction
     * (ChallengeRepository::consumeInTransaction(): refused outside one).
     * Returns the challenge only when it is the member's, was neither
     * withdrawn nor replaced, and carries exactly $txHash as its reserved
     * hash.
     *
     * Those checks run on the LOCKED row before it is deleted, so a null
     * has deleted nothing: trying another member's challenge, or with the
     * wrong hash, leaves it intact even if the caller then commits. (The
     * claim itself still rolls back on null, as any refusal does.)
     *
     * @return array<string, mixed>|null
     */
    public static function consumeInTransaction(string $id, int $userId, string $txHash): ?array
    {
        $hash = self::normalizeHash($txHash);
        if (!self::isId($id) || $userId <= 0 || $hash === null) {
            return null;
        }
        return ChallengeRepository::consumeInTransaction(
            self::KEY_PREFIX . $id,
            static fn (array $challenge): bool => self::usableBy($challenge, $userId)
                && hash_equals((string) ($challenge['reserved_hash'] ?? ''), $hash)
        );
    }

    /**
     * Which challenge a (valoper, code) pair names, while it is LIVE
     * (inside its window) and UNRESERVED: for the operator-facing lookup
     * (design A5) that tells the operator, before they send, which member a
     * code would link their validator to.
     *
     * Null alike for every other case, so a caller can answer each the same
     * way: a malformed valoper or code, an unknown code, a code naming
     * another validator, a window that has ended, a hash already reserved,
     * a withdrawn or replaced challenge. The valoper and the code are
     * compared with hash_equals().
     *
     * @return array{id: string, user_id: int, valoper: string, issued_at: int, window_ends_at: int}|null
     */
    public static function findLiveByCode(string $valoper, string $code): ?array
    {
        $valoper = self::canonicalValoper($valoper);
        if ($valoper === null || !self::isCode($code)) {
            return null;
        }
        $index     = ChallengeRepository::peek(self::CODE_INDEX . $code);
        $id        = is_array($index) ? (string) ($index['id'] ?? '') : '';
        $challenge = self::peek($id);
        if ($challenge === null
            || !hash_equals((string) ($challenge['valoper'] ?? ''), $valoper)
            || !hash_equals((string) ($challenge['code'] ?? ''), $code)
            || time() > (int) ($challenge['window_ends_at'] ?? 0)
            || (string) ($challenge['reserved_hash'] ?? '') !== ''
            || (int) ($challenge['withdrawn_at'] ?? 0) !== 0
        ) {
            return null;
        }
        return [
            'id'             => $id,
            'user_id'        => (int) ($challenge['user_id'] ?? 0),
            'valoper'        => $valoper,
            'issued_at'      => (int) ($challenge['issued_at'] ?? 0),
            'window_ends_at' => (int) ($challenge['window_ends_at'] ?? 0),
        ];
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $challenge */
    private static function usableBy(array $challenge, int $userId): bool
    {
        return ($challenge['purpose'] ?? '') === self::PURPOSE
            && (int) ($challenge['user_id'] ?? 0) === $userId
            && (int) ($challenge['withdrawn_at'] ?? 0) === 0;
    }

    /**
     * Close a replaced challenge: it can no longer be reserved or consumed.
     * The reserved-hash check is made AGAIN here, under the row's lock, so a
     * reserve() that landed after issue() looked is never withdrawn: its
     * hash is verifying. True only when this call closed it.
     */
    private static function endWindow(string $id, int $now): bool
    {
        return ChallengeRepository::update(self::KEY_PREFIX . $id, static function (array $challenge) use ($now): ?array {
            if ((string) ($challenge['reserved_hash'] ?? '') !== '') {
                return null;
            }
            $challenge['withdrawn_at'] = $now;
            return $challenge;
        }) !== null;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private static function liveCount(array $entries, int $now, ?string $except): int
    {
        $n = 0;
        foreach ($entries as $id => $entry) {
            if ((string) $id !== $except && (int) ($entry['window_ends_at'] ?? 0) >= $now) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * An index's entries still worth keeping: live or verifying, or issued
     * in the last hour (the hourly limit).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readIndex(string $key, int $now): array
    {
        $index   = ChallengeRepository::peek($key);
        $entries = is_array($index['entries'] ?? null) ? $index['entries'] : [];
        $out     = [];
        foreach ($entries as $id => $entry) {
            if (is_array($entry) && self::keepUntil($entry) >= $now) {
                $out[(string) $id] = $entry;
            }
        }
        return $out;
    }

    /** @param array<string, array<string, mixed>> $entries */
    private static function writeIndex(string $key, array $entries, int $now): void
    {
        $until = $now + 1;
        foreach ($entries as $entry) {
            $until = max($until, self::keepUntil($entry));
        }
        ChallengeRepository::store($key, ['expires_at' => $until, 'entries' => $entries], $until - $now);
    }

    /** @param array<string, mixed> $entry */
    private static function keepUntil(array $entry): int
    {
        return max((int) ($entry['expires_at'] ?? 0), (int) ($entry['issued_at'] ?? 0) + self::HOUR);
    }

    private static function valoperDigest(string $valoper): string
    {
        return substr(hash('sha256', $valoper), 0, 32);
    }

    private static function isId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{24}\z/', $id) === 1;
    }

    /** A transaction hash as 64 uppercase hex digits, or null. */
    private static function normalizeHash(string $hash): ?string
    {
        return preg_match('/\A[0-9a-fA-F]{64}\z/', $hash) === 1 ? strtoupper($hash) : null;
    }

    /**
     * @return array{ok: bool, reason: string, challenge: null, existing_id: string}
     */
    private static function refused(string $reason, string $existingId = ''): array
    {
        return ['ok' => false, 'reason' => $reason, 'challenge' => null, 'existing_id' => $existingId];
    }
}
