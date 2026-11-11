<?php
/**
 * Shared wallet challenge (nonce) generation.
 *
 * Both bcc-trust's Core (REST) and Onchain (AJAX) controllers use this
 * class to generate the signing message so that:
 *   1. The message format is identical across entry points
 *   2. Cosmos ADR-036 signing works consistently
 *   3. Changes to the message format happen in one place
 *
 * Every verifier checks the signature against the STORED message, never a
 * rebuilt one, so the wording can change without touching a verifier.
 *
 * Wording (F-25, user-approved 2026-11-11). The message names the site the
 * person is on — the canonical frontend host from config, so staging names
 * its own host — says it costs nothing, and carries the chain so a
 * signature for one chain cannot be presented for another:
 *   sign in:  "Sign in to Blue Collar Crypto (<host>). This request will not
 *              trigger a transaction or cost any fees. Code: <nonce> Chain: <slug>"
 *   verify:   "Verify your wallet on Blue Collar Crypto (<host>). …" (wallet
 *              linking and validator claims)
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

use BCC\Core\Support\FrontendOriginConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletChallenge
{
    /** Anonymous wallet sign-in / sign-up (/auth/wallet-nonce). */
    public const PURPOSE_SIGN_IN = 'sign_in';

    /** Authed wallet linking and validator claims (/auth/nonce). */
    public const PURPOSE_VERIFY = 'verify';

    private const LEADS = [
        self::PURPOSE_SIGN_IN => 'Sign in to Blue Collar Crypto',
        self::PURPOSE_VERIFY  => 'Verify your wallet on Blue Collar Crypto',
    ];

    private const NO_COST = 'This request will not trigger a transaction or cost any fees.';

    /**
     * Generate a challenge message for wallet signing.
     *
     * @param string $chainSlug Chain identifier embedded in the message
     *                          to prevent cross-chain signature replay.
     * @param string $purpose   PURPOSE_SIGN_IN or PURPOSE_VERIFY.
     * @return array{nonce: string, message: string}
     */
    public static function generate(string $chainSlug = '', string $purpose = self::PURPOSE_VERIFY): array
    {
        if (!isset(self::LEADS[$purpose])) {
            throw new \InvalidArgumentException('Unknown wallet challenge purpose: ' . $purpose);
        }

        $nonce = bin2hex(random_bytes(16));
        $host  = FrontendOriginConfig::publicHost();

        $message = self::LEADS[$purpose]
            . ($host !== '' ? ' (' . $host . ')' : '')
            . '. ' . self::NO_COST
            . ' Code: ' . $nonce
            . ($chainSlug !== '' ? ' Chain: ' . $chainSlug : '');

        return [
            'nonce'   => $nonce,
            'message' => $message,
        ];
    }
}
