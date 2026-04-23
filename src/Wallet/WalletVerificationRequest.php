<?php
/**
 * Value object bundling the parameters for a wallet verification + link operation.
 *
 * Replaces the 11-parameter signature of WalletIdentityService::verifyAndLink()
 * with a single typed object, eliminating positional-argument bugs.
 *
 * @package BCC\Core\Wallet
 */

namespace BCC\Core\Wallet;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletVerificationRequest
{
    public int $userId;
    public string $chainSlug;
    public string $chainType;
    public int $chainId;
    public string $walletAddress;
    public string $signature;
    /** @var array<string, mixed> */
    public array $extra;
    public int $postId;
    public string $walletType;
    public string $label;

    private function __construct() {}

    /**
     * Build from named keys (the typical controller call-site pattern).
     *
     * The challenge message is intentionally NOT accepted from the
     * caller. WalletIdentityService::verifyAndLink() sources it from
     * the atomically consumed challenge so it is always server-known.
     *
     * @param array{
     *     userId: int,
     *     chainSlug: string,
     *     chainType: string,
     *     chainId: int,
     *     walletAddress: string,
     *     signature: string,
     *     extra?: array<string, mixed>,
     *     postId?: int,
     *     walletType?: string,
     *     label?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->userId        = (int) $data['userId'];
        $r->chainSlug     = (string) $data['chainSlug'];
        $r->chainType     = (string) $data['chainType'];
        $r->chainId       = (int) $data['chainId'];
        $r->walletAddress = (string) $data['walletAddress'];
        $r->signature     = (string) $data['signature'];
        $r->extra         = $data['extra'] ?? [];
        $r->postId        = (int) ($data['postId'] ?? 0);
        $r->walletType    = (string) ($data['walletType'] ?? 'user');
        $r->label         = (string) ($data['label'] ?? '');
        return $r;
    }
}
