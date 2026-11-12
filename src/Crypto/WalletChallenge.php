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

    /**
     * F-253: a signed-in member confirms a sensitive account change with a
     * wallet signature instead of a password (wallet-only accounts never had
     * one). One purpose per change, so the member sees in their wallet exactly
     * what they are approving, and a signature for one change can never be
     * spent on another (or on wallet linking): the purpose is stored with the
     * challenge and checked when it is consumed.
     */
    public const PURPOSE_CONFIRM_EMAIL_CHANGE    = 'confirm_email_change';
    public const PURPOSE_CONFIRM_PASSWORD_CHANGE = 'confirm_password_change';
    public const PURPOSE_CONFIRM_ACCOUNT_DELETE  = 'confirm_account_delete';

    /** The purposes a signed-in member may request a challenge for (/auth/nonce). */
    public const AUTHED_PURPOSES = [
        self::PURPOSE_VERIFY,
        self::PURPOSE_CONFIRM_EMAIL_CHANGE,
        self::PURPOSE_CONFIRM_PASSWORD_CHANGE,
        self::PURPOSE_CONFIRM_ACCOUNT_DELETE,
    ];

    private const LEADS = [
        self::PURPOSE_SIGN_IN                => 'Sign in to Blue Collar Crypto',
        self::PURPOSE_VERIFY                 => 'Verify your wallet on Blue Collar Crypto',
        self::PURPOSE_CONFIRM_EMAIL_CHANGE    => 'Confirm changing the email address of your Blue Collar Crypto account',
        self::PURPOSE_CONFIRM_PASSWORD_CHANGE => 'Confirm changing the password of your Blue Collar Crypto account',
        self::PURPOSE_CONFIRM_ACCOUNT_DELETE  => 'Confirm deleting your Blue Collar Crypto account',
    ];

    /** Said before the no-cost line, for changes that cannot be taken back. */
    private const WARNINGS = [
        self::PURPOSE_CONFIRM_ACCOUNT_DELETE => 'This cannot be undone.',
    ];

    private const NO_COST = 'This request will not trigger a transaction or cost any fees.';

    public static function isPurpose(string $purpose): bool
    {
        return isset(self::LEADS[$purpose]);
    }

    /**
     * Generate a challenge message for wallet signing.
     *
     * @param string $chainSlug Chain identifier embedded in the message
     *                          to prevent cross-chain signature replay.
     * @param string $purpose   One of the PURPOSE_* constants.
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
            . '. ' . (isset(self::WARNINGS[$purpose]) ? self::WARNINGS[$purpose] . ' ' : '')
            . self::NO_COST
            . ' Code: ' . $nonce
            . ($chainSlug !== '' ? ' Chain: ' . $chainSlug : '');

        return [
            'nonce'   => $nonce,
            'message' => $message,
        ];
    }
}
