<?php

namespace BCC\Core;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\Contracts\ScoreContributorInterface;
use BCC\Core\Contracts\ScoreReadServiceInterface;
use BCC\Core\Contracts\PageOwnerResolverInterface;
use BCC\Core\Contracts\TrustHeaderDataInterface;
use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Contracts\WalletLinkReadInterface;
use BCC\Core\Contracts\WalletLinkWriteInterface;
use BCC\Core\Contracts\WalletVerificationReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceLocator
{
    /** @var array<string, mixed> Memoized service instances, keyed by filter name. */
    private static array $cache = [];

    public static function resolveDisputeAdjudication(): ?DisputeAdjudicationInterface
    {
        return self::resolveOnce('bcc.resolve.dispute_adjudication', DisputeAdjudicationInterface::class);
    }

    public static function resolveTrustReadService(): ?TrustReadServiceInterface
    {
        return self::resolveOnce('bcc.resolve.trust_read_service', TrustReadServiceInterface::class);
    }

    public static function resolveScoreContributor(): ?ScoreContributorInterface
    {
        return self::resolveOnce('bcc.resolve.score_contributor', ScoreContributorInterface::class);
    }

    public static function resolveScoreReadService(): ?ScoreReadServiceInterface
    {
        return self::resolveOnce('bcc.resolve.score_read_service', ScoreReadServiceInterface::class);
    }

    public static function resolveTrustHeaderData(): ?TrustHeaderDataInterface
    {
        return self::resolveOnce('bcc.resolve.trust_header_data', TrustHeaderDataInterface::class);
    }

    public static function resolvePageOwnerResolver(): ?PageOwnerResolverInterface
    {
        return self::resolveOnce('bcc.resolve.page_owner_resolver', PageOwnerResolverInterface::class);
    }

    public static function resolveWalletVerificationRead(): ?WalletVerificationReadInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_verification_read', WalletVerificationReadInterface::class);
    }

    public static function resolveWalletLinkRead(): ?WalletLinkReadInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_link_read', WalletLinkReadInterface::class);
    }

    public static function resolveWalletLinkWrite(): ?WalletLinkWriteInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_link_write', WalletLinkWriteInterface::class);
    }

    /**
     * Resolve a service via its filter hook, caching the result for the lifetime of the request.
     *
     * @template T
     * @param string $filter   The filter hook name.
     * @param class-string<T> $contract The interface the resolved service must implement.
     * @return T|null
     */
    private static function resolveOnce(string $filter, string $contract)
    {
        if (array_key_exists($filter, self::$cache)) {
            return self::$cache[$filter];
        }

        $service = apply_filters($filter, null);

        self::$cache[$filter] = $service instanceof $contract ? $service : null;

        return self::$cache[$filter];
    }

    /**
     * Flush the resolved-service cache. Useful in unit tests or
     * long-running processes that need to re-resolve services.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
