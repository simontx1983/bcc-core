<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletVerificationReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 */
final class NullWalletVerificationRead implements WalletVerificationReadInterface
{
    public function hasVerifiedWallet(int $userId): bool
    {
        return false;
    }

    public function hasVerification(int $userId, string $type): bool
    {
        return false;
    }
}
