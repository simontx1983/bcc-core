<?php
/**
 * The validator memo claim's challenge (design §4.1, §4.2; A2).
 *
 * A signed-in member asks to claim a validator page without connecting a
 * wallet. The server issues a challenge carrying the exact memo the
 * operator must send, from the operator account to itself:
 *
 *   BCC: claim validator page <valoper> for @<handle> on <host>. Code <code>
 *
 * - The code is 8 symbols from a CSPRNG over an alphabet without the
 *   look-alikes 0 O 1 I (and no lowercase, so no l): 40 bits, used once.
 * - The memo is printable ASCII, at most 256 bytes (the Cosmos SDK's
 *   default memo limit), and compared byte for byte (memoMatches()).
 * - A challenge is LIVE for 30 minutes, which bounds INCLUSION: the
 *   block time must fall in [issued − 60 s, issued + 30 min]. It is kept
 *   for 30 minutes more so a submitted hash can finish verifying.
 * - The submitted hash is RESERVED on the challenge (reserve()): one hash
 *   per challenge.
 * - The claim consumes it, inside the claim's own transaction
 *   (consumeInTransaction()).
 * - A changed handle, a withdrawal, a newer challenge on the same valoper
 *   or another member each make it unusable.
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

    /** A member handle as HandleService allows it: 3–20 of [a-z0-9-]. */
    public static function isHandle(string $handle): bool
    {
        return preg_match('/\A[a-z0-9-]{3,20}\z/', $handle) === 1;
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
    public static function memo(string $valoper, string $handle, string $host, string $code): ?string
    {
        $valoper = self::canonicalValoper($valoper);
        if ($valoper === null
            || !self::isHandle($handle)
            || preg_match('/\A[a-z0-9.-]+(:[0-9]{1,5})?\z/', $host) !== 1
            || !self::isCode($code)
        ) {
            return null;
        }
        $memo = 'BCC: claim validator page ' . $valoper . ' for @' . $handle . ' on ' . $host . '. Code ' . $code;
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
     * Issue a challenge to $userId (whose handle is $handle) for the
     * validator $valoper.
     *
     * @return array{ok: bool, reason: string, challenge: array<string, mixed>|null, existing_id: string}
     *         `existing_id`: on REFUSED_IN_VERIFICATION, the member's challenge
     *         for this valoper whose hash is being verified.
     */
    public static function issue(int $userId, string $handle, string $valoper): array
    {
        $valoper = self::canonicalValoper($valoper);
        if ($userId <= 0 || $valoper === null || !self::isHandle($handle)) {
            return self::refused(self::REFUSED_BAD_INPUT);
        }
        $host = FrontendOriginConfig::publicHost();
        $code = self::code();
        $memo = self::memo($valoper, $handle, $host, $code);
        if ($memo === null) {
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

            if ($own !== null) {
                self::endWindow($own, $now);
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
                'handle'         => $handle,
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
     * hash is pasted). Refused when the challenge is another member's, was
     * withdrawn or replaced, or names a handle the member no longer has.
     *
     * @return array<string, mixed>|null the reserved challenge
     */
    public static function reserve(string $id, int $userId, string $currentHandle, string $txHash): ?array
    {
        $hash = self::normalizeHash($txHash);
        if (!self::isId($id) || $userId <= 0 || $hash === null) {
            return null;
        }
        return ChallengeRepository::update(
            self::KEY_PREFIX . $id,
            static function (array $challenge) use ($userId, $currentHandle, $hash): ?array {
                if (!self::usableBy($challenge, $userId, $currentHandle)) {
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
     * Returns the challenge only when it is the member's, names their
     * current handle, was neither withdrawn nor replaced, and carries
     * exactly $txHash as its reserved hash.
     *
     * ⚠ On null the caller MUST roll back: the row may already be deleted
     * inside the caller's transaction, and the rollback restores it.
     *
     * @return array<string, mixed>|null
     */
    public static function consumeInTransaction(string $id, int $userId, string $currentHandle, string $txHash): ?array
    {
        $hash = self::normalizeHash($txHash);
        if (!self::isId($id) || $userId <= 0 || $hash === null) {
            return null;
        }
        $challenge = ChallengeRepository::consumeInTransaction(self::KEY_PREFIX . $id);
        if ($challenge === null
            || !self::usableBy($challenge, $userId, $currentHandle)
            || !hash_equals((string) ($challenge['reserved_hash'] ?? ''), $hash)
        ) {
            return null;
        }
        return $challenge;
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $challenge */
    private static function usableBy(array $challenge, int $userId, string $currentHandle): bool
    {
        return ($challenge['purpose'] ?? '') === self::PURPOSE
            && (int) ($challenge['user_id'] ?? 0) === $userId
            && $currentHandle !== ''
            && hash_equals((string) ($challenge['handle'] ?? ''), $currentHandle)
            && (int) ($challenge['withdrawn_at'] ?? 0) === 0;
    }

    /** Close a replaced challenge: it can no longer be reserved or consumed. */
    private static function endWindow(string $id, int $now): void
    {
        ChallengeRepository::update(self::KEY_PREFIX . $id, static function (array $challenge) use ($now): array {
            $challenge['withdrawn_at'] = $now;
            return $challenge;
        });
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
