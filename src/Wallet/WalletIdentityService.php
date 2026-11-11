<?php
/**
 * WalletIdentityService — single source of truth for wallet operations.
 *
 * Consolidates challenge generation, signature verification, wallet
 * linking/unlinking, and primary-wallet management into one service
 * that both REST and AJAX controllers delegate to.
 *
 * Storage decisions:
 *   - Challenges: transients (auto-expire, no orphan cleanup needed)
 *   - Wallet links: bcc_wallet_links via WalletLinkWriteInterface
 *
 * This class owns the WHAT. Controllers own the HOW (HTTP transport).
 *
 * @package BCC\Core\Wallet
 */

namespace BCC\Core\Wallet;

use BCC\Core\Crypto\WalletChallenge;
use BCC\Core\Crypto\WalletVerifier;
use BCC\Core\Log\Logger;
use BCC\Core\ServiceLocator;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletIdentityService
{
    /** Challenge TTL in seconds (5 minutes). */
    private const CHALLENGE_TTL = 300;

    // ── Challenge ───────────────────────────────────────────────────────

    /**
     * Generate and store a signing challenge for a wallet verification.
     *
     * The challenge is stored as a transient keyed by user + chain + address.
     * This key includes the address so a user can have concurrent challenges
     * for different wallets without one overwriting the other.
     *
     * @param int    $userId        WordPress user ID.
     * @param string $chainSlug     Chain slug (e.g. 'ethereum', 'cosmos').
     * @param int    $chainId       Numeric chain ID from bcc_chains table.
     * @param string $walletAddress Wallet public address.
     * @return array{nonce: string, message: string, chain_slug: string, chain_id: int, wallet_address: string, expires_at: int}
     */
    public static function generateChallenge(
        int $userId,
        string $chainSlug,
        int $chainId,
        string $walletAddress
    ): array {
        $challenge = WalletChallenge::generate($chainSlug, WalletChallenge::PURPOSE_VERIFY);

        $payload = [
            'nonce'          => $challenge['nonce'],
            'message'        => $challenge['message'],
            'chain_slug'     => $chainSlug,
            'chain_id'       => $chainId,
            'wallet_address' => strtolower($walletAddress),
            'expires_at'     => time() + self::CHALLENGE_TTL,
        ];

        $key = self::challengeKey($userId, $walletAddress);
        ChallengeRepository::store($key, $payload, self::CHALLENGE_TTL);

        return $payload;
    }

    /**
     * Retrieve and consume a stored challenge (one-time use).
     *
     * Uses a MySQL advisory lock to make the get+delete atomic, preventing
     * two concurrent requests from both consuming the same challenge.
     *
     * Returns null if the challenge does not exist, has expired, or the
     * address does not match the one used at generation time.
     *
     * @return array<string, mixed>|null The challenge payload, or null.
     */
    public static function consumeChallenge(int $userId, string $walletAddress): ?array
    {
        $key = self::challengeKey($userId, $walletAddress);

        // Atomic get+delete via repository (SELECT … FOR UPDATE in a transaction).
        $challenge = ChallengeRepository::consume($key);

        if ($challenge === null) {
            return null;
        }

        // Expired (belt-and-suspenders — transient TTL should handle this).
        if (time() > (int) ($challenge['expires_at'] ?? 0)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Non-destructive view of a stored challenge.
     *
     * Returns the challenge payload without deleting it so a controller
     * can read chain metadata (chain_id etc.) BEFORE handing control to
     * verifyAndLink(), which performs the atomic consume. A race between
     * peek and consume is safe: the loser of the consume gets a failure
     * response and no state is mutated.
     *
     * @return array<string, mixed>|null
     */
    public static function peekChallenge(int $userId, string $walletAddress): ?array
    {
        // Direct-DB peek so an object-cache drop-in cannot shadow the
        // direct-write store(); pairs with consumeChallenge().
        return ChallengeRepository::peek(self::challengeKey($userId, $walletAddress));
    }

    // ── Anonymous challenge (wallet-as-credential auth) ─────────────────

    /** Most live anonymous challenges read per address (one per chain). */
    private const ANON_CANDIDATE_LIMIT = 20;

    /** Seconds generateAnonymousChallenge() waits for its per-key lock. */
    private const ANON_LOCK_WAIT = 2;

    /**
     * Generate — or return the still-live — wallet sign-in challenge for an
     * unauthenticated caller.
     *
     * Used by /auth/wallet-nonce — the entry point for both wallet-login
     * (existing user, wallet already linked) and wallet-signup (new user,
     * wallet about to be linked). No userId is bound to the challenge —
     * the address (+ chain) is the namespace, since only the address-holder
     * can produce a signature.
     *
     * F-25: a LIVE challenge is never overwritten. Anyone may call
     * /auth/wallet-nonce for any address, so overwriting let a stranger
     * replace the challenge a wallet owner was about to sign. A second
     * request for the same (address, chain) now gets the same challenge
     * back until it expires; that discloses nothing usable, since a
     * signature from the wallet is still required. Challenges are stored
     * per (address, chain), so a request for another chain neither
     * replaces nor pins one: EVM addresses are shared across EVM chains.
     * The peek-then-store runs under a per-key advisory lock; if the lock
     * cannot be had the store proceeds (the pre-F-25 behaviour).
     *
     * Storage keyspace is disjoint from the authed challenges
     * (`bcc_wc_anon_*` vs. `bcc_wc_{userId}_*`) so an anonymous challenge
     * cannot be replayed against the authed wallet-link path or vice
     * versa. Same TTL + transient backing as the authed flow.
     *
     * @return array{nonce: string, message: string, chain_slug: string, chain_id: int, wallet_address: string, expires_at: int}
     */
    public static function generateAnonymousChallenge(
        string $chainSlug,
        int $chainId,
        string $walletAddress
    ): array {
        $key    = self::anonymousChallengeKey($walletAddress, $chainId);
        $locked = \BCC\Core\DB\AdvisoryLock::acquire($key, self::ANON_LOCK_WAIT);

        try {
            $live = ChallengeRepository::peek($key);
            if ($live !== null) {
                return [
                    'nonce'          => (string) ($live['nonce'] ?? ''),
                    'message'        => (string) ($live['message'] ?? ''),
                    'chain_slug'     => (string) ($live['chain_slug'] ?? ''),
                    'chain_id'       => (int) ($live['chain_id'] ?? 0),
                    'wallet_address' => (string) ($live['wallet_address'] ?? ''),
                    'expires_at'     => (int) ($live['expires_at'] ?? 0),
                ];
            }

            $challenge = WalletChallenge::generate($chainSlug, WalletChallenge::PURPOSE_SIGN_IN);

            $payload = [
                'nonce'          => $challenge['nonce'],
                'message'        => $challenge['message'],
                'chain_slug'     => $chainSlug,
                'chain_id'       => $chainId,
                'wallet_address' => strtolower($walletAddress),
                'expires_at'     => time() + self::CHALLENGE_TTL,
            ];

            ChallengeRepository::store($key, $payload, self::CHALLENGE_TTL);

            return $payload;
        } finally {
            if ($locked) {
                \BCC\Core\DB\AdvisoryLock::release($key);
            }
        }
    }

    /**
     * Every live anonymous challenge for an address — at most one per chain
     * — in key order. Non-destructive.
     *
     * F-25: sign-in callers verify the signature against these BEFORE
     * consuming anything, then consume only the challenge it verified
     * against (consumeAnonymousChallenge with its chain id). So a junk
     * request can no longer burn a wallet owner's pending challenge.
     *
     * Also returns a challenge stored under the pre-F-25 address-only key
     * (it is a prefix of the per-chain keys), so challenges issued just
     * before a deploy still work for their 5-minute life.
     *
     * @return list<array<string, mixed>>
     */
    public static function peekAnonymousChallenges(string $walletAddress): array
    {
        return array_values(ChallengeRepository::peekByPrefix(
            self::anonymousChallengePrefix($walletAddress),
            self::ANON_CANDIDATE_LIMIT
        ));
    }

    /**
     * Atomically retrieve and consume an anonymous challenge.
     *
     * With $chainId: consumes that chain's challenge — the one the caller
     * verified a signature against. Without it (pre-F-25 callers): consumes
     * the first live challenge for the address, whatever its chain.
     *
     * Returns null when no matching challenge exists, it has expired, or a
     * concurrent caller consumed it first.
     *
     * @return array<string, mixed>|null
     */
    public static function consumeAnonymousChallenge(string $walletAddress, ?int $chainId = null): ?array
    {
        $candidates = ChallengeRepository::peekByPrefix(
            self::anonymousChallengePrefix($walletAddress),
            self::ANON_CANDIDATE_LIMIT
        );

        foreach ($candidates as $key => $payload) {
            if ($chainId !== null && (int) ($payload['chain_id'] ?? 0) !== $chainId) {
                continue;
            }

            $challenge = ChallengeRepository::consume($key);
            if ($challenge === null || time() > (int) ($challenge['expires_at'] ?? 0)) {
                return null;
            }
            return $challenge;
        }

        return null;
    }

    /**
     * Non-destructive view of an anonymous challenge (pre-F-25 callers):
     * the first live one for the address, whatever its chain.
     *
     * @return array<string, mixed>|null
     */
    public static function peekAnonymousChallenge(string $walletAddress): ?array
    {
        return self::peekAnonymousChallenges($walletAddress)[0] ?? null;
    }

    // ── Verification ────────────────────────────────────────────────────

    /**
     * Verify a wallet signature and link the wallet in one atomic operation.
     *
     * This is the SINGLE execution pipeline for all wallet verifications,
     * regardless of whether the request arrived via REST or AJAX.
     *
     * The challenge message is NEVER trusted from caller input — it is
     * consumed atomically from the ChallengeRepository at step 1. This
     * closes the footgun where a future call-site could pass a
     * user-supplied `$_POST['message']` and bypass nonce enforcement.
     *
     * On success, fires `bcc_wallet_verified` so listeners (bcc-trust
     * Core scoring, Onchain seeding) can react.
     *
     * @param WalletVerificationRequest $req All verification parameters.
     * @return array{success: bool, wallet_link_id: int, message: string}
     */
    public static function verifyAndLink(WalletVerificationRequest $req): array
    {
        // 1. Atomically consume the stored challenge. If it is missing
        //    or expired we must refuse — this prevents replay and also
        //    prevents verification against an attacker-supplied message.
        //
        //    Severity note: this is NOT a security event — the most
        //    common cause is a frontend integration issue (duplicate
        //    submit, stale tab, user clicked verify before challenge
        //    request finished). Logged at warning so ops paging
        //    thresholds do not fire on normal UX hiccups. A genuine
        //    replay attempt would still bubble up as a pattern in the
        //    warning stream.
        $challenge = self::consumeChallenge($req->userId, $req->walletAddress);
        if ($challenge === null) {
            Logger::warning('[WalletIdentity] Challenge missing or expired', [
                'user_id' => $req->userId,
                'chain'   => $req->chainSlug,
                'address' => $req->walletAddress,
            ]);
            return [
                'success'        => false,
                'wallet_link_id' => 0,
                'message'        => 'Challenge not found or expired. Please try again.',
            ];
        }

        $challengeMessage = isset($challenge['message']) ? (string) $challenge['message'] : '';
        if ($challengeMessage === '') {
            Logger::error('[WalletIdentity] Stored challenge is malformed', [
                'user_id' => $req->userId,
                'chain'   => $req->chainSlug,
            ]);
            return [
                'success'        => false,
                'wallet_link_id' => 0,
                'message'        => 'Challenge malformed.',
            ];
        }

        // 2. Cryptographic verification (CPU-only, no network I/O).
        //    The message is sourced from the consumed challenge above
        //    so it is always server-authoritative.
        $valid = WalletVerifier::verify(
            $req->chainType,
            $challengeMessage,
            $req->signature,
            $req->walletAddress,
            $req->extra
        );

        if (!$valid) {
            Logger::error('[WalletIdentity] Signature verification failed', [
                'user_id' => $req->userId,
                'chain'   => $req->chainSlug,
                'address' => $req->walletAddress,
            ]);
            return [
                'success'        => false,
                'wallet_link_id' => 0,
                'message'        => 'Signature verification failed.',
            ];
        }

        // For Polkadot, replace the user-submitted address with the
        // canonical prefix-0 SS58 form returned by the verifier. Dedups
        // storage by underlying public key — a user whose wallet renders
        // in prefix 42 (the Polkadot.js "Substrate" default) signs up to
        // the same row as a wallet rendering in prefix 0. Other chains
        // pass through unchanged.
        $effectiveAddress = $req->walletAddress;
        if ($req->chainType === 'polkadot' && \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress !== null) {
            $effectiveAddress = \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress;
        }

        // 3. Link wallet via the canonical write contract.
        $walletLinkId = ServiceLocator::resolveWalletLinkWrite()->linkWallet(
            $req->userId,
            $req->chainSlug,
            $effectiveAddress,
            $req->postId,
            $req->walletType,
            $req->label
        );

        if (!$walletLinkId) {
            Logger::error('[WalletIdentity] Wallet link write failed', [
                'user_id'  => $req->userId,
                'chain_id' => $req->chainId,
            ]);
            return [
                'success'        => false,
                'wallet_link_id' => 0,
                'message'        => 'Failed to save wallet.',
            ];
        }

        // 4. Fire the canonical domain event.
        //    Listeners:
        //    - bcc-trust Core CronService: creates bcc_onchain_signals scoring row
        //    - bcc-trust Onchain WalletSeedService: populates on-chain data
        do_action('bcc_wallet_verified', $req->userId, $req->chainSlug, $effectiveAddress);

        return [
            'success'        => true,
            'wallet_link_id' => $walletLinkId,
            'message'        => 'Wallet verified.',
        ];
    }

    // ── Disconnect ──────────────────────────────────────────────────────

    /**
     * Unlink a wallet and fire the disconnected event.
     *
     * @param int    $userId        WordPress user ID.
     * @param string $chainSlug     Chain slug.
     * @param string $walletAddress Wallet address.
     * @return bool True if the wallet was unlinked.
     */
    public static function unlinkWallet(
        int $userId,
        string $chainSlug,
        string $walletAddress
    ): bool {
        $deleted = ServiceLocator::resolveWalletLinkWrite()->unlinkWallet(
            $userId,
            $chainSlug,
            $walletAddress
        );

        if ($deleted) {
            /**
             * Fires after a wallet is disconnected.
             *
             * @param int    $userId WordPress user ID.
             * @param string $chainSlug Chain slug.
             * @param string $walletAddress Wallet address.
             */
            do_action('bcc_wallet_disconnected', $userId, $chainSlug, $walletAddress);
        }

        return $deleted;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Build the transient key for a wallet challenge.
     *
     * Format: bcc_wc_{userId}_{addressHash}
     * Short prefix to stay within the 172-char transient key limit.
     */
    private static function challengeKey(int $userId, string $address): string
    {
        return 'bcc_wc_' . $userId . '_' . md5(strtolower($address));
    }

    /**
     * Build the transient key for an anonymous wallet challenge.
     *
     * Format: bcc_wc_anon_{addressHash}_{chainId} (F-25; before it, the key
     * had no chain suffix — still readable via anonymousChallengePrefix()).
     * Disjoint from challengeKey() (which prefixes a userId) so an anon
     * challenge can never be replayed against the authed wallet-link
     * path, and vice-versa. The address (+ chain) is the only namespace —
     * since only the address-holder can produce a valid signature, this
     * is sufficient.
     */
    private static function anonymousChallengeKey(string $address, int $chainId): string
    {
        return self::anonymousChallengePrefix($address) . '_' . $chainId;
    }

    /**
     * Key prefix shared by every anonymous challenge for an address — and
     * the whole pre-F-25 key, which carried no chain.
     */
    private static function anonymousChallengePrefix(string $address): string
    {
        return 'bcc_wc_anon_' . md5(strtolower($address));
    }
}
