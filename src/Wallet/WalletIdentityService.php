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
     * @return array{nonce: string, message: string, chain_id: int}
     */
    public static function generateChallenge(
        int $userId,
        string $chainSlug,
        int $chainId,
        string $walletAddress
    ): array {
        $challenge = WalletChallenge::generate($chainSlug);

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
        if (time() > ($challenge['expires_at'] ?? 0)) {
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
        $key = self::challengeKey($userId, $walletAddress);

        $challenge = get_transient($key);
        if (!is_array($challenge)) {
            return null;
        }

        if (time() > (int) ($challenge['expires_at'] ?? 0)) {
            return null;
        }

        return $challenge;
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
     * On success, fires `bcc_wallet_verified` so listeners (trust-engine
     * scoring, onchain-signals seeding) can react.
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

        // 3. Link wallet via the canonical write contract.
        $walletLinkId = ServiceLocator::resolveWalletLinkWrite()->linkWallet(
            $req->userId,
            $req->chainSlug,
            $req->walletAddress,
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
        //    - trust-engine CronService: creates bcc_onchain_signals scoring row
        //    - onchain-signals WalletSeedService: populates on-chain data
        do_action('bcc_wallet_verified', $req->userId, $req->chainSlug, $req->walletAddress);

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
}
