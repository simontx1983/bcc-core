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
use BCC\Core\Contracts\QuestProgressReadInterface;
use BCC\Core\Contracts\WalletVerificationReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceLocator
{
    /** @var array<string, mixed> Memoized service instances, keyed by filter name. */
    private static array $cache = [];

    /**
     * Map of contract interface → NullObject class for safe fallback.
     *
     * When a provider plugin is not active (or hasn't loaded yet), the
     * corresponding NullObject is returned instead of null. This
     * eliminates null-check obligations at every call site and prevents
     * fatals caused by plugin load-order or deactivation.
     *
     * @var array<class-string, class-string>
     */
    private static array $nullObjects = [
        DisputeAdjudicationInterface::class    => \BCC\Core\NullServices\NullDisputeAdjudication::class,
        TrustReadServiceInterface::class       => \BCC\Core\NullServices\NullTrustReadService::class,
        ScoreContributorInterface::class       => \BCC\Core\NullServices\NullScoreContributor::class,
        ScoreReadServiceInterface::class       => \BCC\Core\NullServices\NullScoreReadService::class,
        TrustHeaderDataInterface::class        => \BCC\Core\NullServices\NullTrustHeaderData::class,
        PageOwnerResolverInterface::class      => \BCC\Core\NullServices\NullPageOwnerResolver::class,
        WalletVerificationReadInterface::class => \BCC\Core\NullServices\NullWalletVerificationRead::class,
        WalletLinkReadInterface::class         => \BCC\Core\NullServices\NullWalletLinkRead::class,
        WalletLinkWriteInterface::class        => \BCC\Core\NullServices\NullWalletLinkWrite::class,
        QuestProgressReadInterface::class      => \BCC\Core\NullServices\NullQuestProgressRead::class,
    ];

    public static function resolveDisputeAdjudication(): DisputeAdjudicationInterface
    {
        return self::resolveOnce('bcc.resolve.dispute_adjudication', DisputeAdjudicationInterface::class);
    }

    public static function resolveTrustReadService(): TrustReadServiceInterface
    {
        return self::resolveOnce('bcc.resolve.trust_read_service', TrustReadServiceInterface::class);
    }

    public static function resolveScoreContributor(): ScoreContributorInterface
    {
        return self::resolveOnce('bcc.resolve.score_contributor', ScoreContributorInterface::class);
    }

    public static function resolveScoreReadService(): ScoreReadServiceInterface
    {
        return self::resolveOnce('bcc.resolve.score_read_service', ScoreReadServiceInterface::class);
    }

    public static function resolveTrustHeaderData(): TrustHeaderDataInterface
    {
        return self::resolveOnce('bcc.resolve.trust_header_data', TrustHeaderDataInterface::class);
    }

    public static function resolvePageOwnerResolver(): PageOwnerResolverInterface
    {
        return self::resolveOnce('bcc.resolve.page_owner_resolver', PageOwnerResolverInterface::class);
    }

    public static function resolveWalletVerificationRead(): WalletVerificationReadInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_verification_read', WalletVerificationReadInterface::class);
    }

    public static function resolveWalletLinkRead(): WalletLinkReadInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_link_read', WalletLinkReadInterface::class);
    }

    public static function resolveWalletLinkWrite(): WalletLinkWriteInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_link_write', WalletLinkWriteInterface::class);
    }

    public static function resolveQuestProgressRead(): QuestProgressReadInterface
    {
        return self::resolveOnce('bcc.resolve.quest_progress_read', QuestProgressReadInterface::class);
    }

    /**
     * Map of contract class → filter name, built from the resolve methods.
     * Used by hasRealService() to look up cached instances by contract.
     *
     * @var array<class-string, string>
     */
    private static array $contractToFilter = [
        DisputeAdjudicationInterface::class    => 'bcc.resolve.dispute_adjudication',
        TrustReadServiceInterface::class       => 'bcc.resolve.trust_read_service',
        ScoreContributorInterface::class       => 'bcc.resolve.score_contributor',
        ScoreReadServiceInterface::class       => 'bcc.resolve.score_read_service',
        TrustHeaderDataInterface::class        => 'bcc.resolve.trust_header_data',
        PageOwnerResolverInterface::class      => 'bcc.resolve.page_owner_resolver',
        WalletVerificationReadInterface::class => 'bcc.resolve.wallet_verification_read',
        WalletLinkReadInterface::class         => 'bcc.resolve.wallet_link_read',
        WalletLinkWriteInterface::class        => 'bcc.resolve.wallet_link_write',
        QuestProgressReadInterface::class      => 'bcc.resolve.quest_progress_read',
    ];

    /**
     * Check whether a real (non-null-object) implementation is available.
     *
     * Triggers resolution if not already cached. Returns false if the
     * resolved service is a NullObject or if no service was resolved.
     *
     * Useful when a caller needs to distinguish "trust engine active" from
     * "running on null fallback" — e.g., to decide whether to queue a retry.
     */
    public static function hasRealService(string $contract): bool
    {
        $filter = self::$contractToFilter[$contract] ?? null;

        if ($filter === null) {
            return false;
        }

        // Trigger resolution if not already cached.
        if (!array_key_exists($filter, self::$cache)) {
            self::resolveOnce($filter, $contract);
        }

        // If the filter resolved to a real service, it's in the cache.
        // NullObjects are NOT cached (by design in resolveOnce), so a
        // cache miss here means only a NullObject was available.
        return array_key_exists($filter, self::$cache);
    }

    /**
     * Resolve a service via its filter hook, caching the result for the
     * lifetime of the request.
     *
     * If no plugin provides an implementation, returns the NullObject
     * fallback for the contract. This is safe:
     *   - NullObjects implement the full contract interface
     *   - They return empty/false/zero values (never throw)
     *   - Callers that need to detect "no real provider" can use
     *     hasRealService() or check the return value of write operations
     *
     * The cache is NOT populated with the NullObject so that a late-
     * loading plugin's filter can still provide the real implementation
     * on the next call within the same request.
     *
     * @template T
     * @param string $filter   The filter hook name.
     * @param class-string<T> $contract The interface the resolved service must implement.
     * @return T
     */
    private static function resolveOnce(string $filter, string $contract)
    {
        if (array_key_exists($filter, self::$cache)) {
            return self::$cache[$filter];
        }

        $service = apply_filters($filter, null);

        if ($service instanceof $contract) {
            self::$cache[$filter] = $service;
            return $service;
        }

        // No real provider available. Return NullObject fallback but do NOT
        // cache it — a plugin that loads later in this request should still
        // be able to provide the real implementation on the next resolve call.
        $nullClass = self::$nullObjects[$contract] ?? null;

        if ($nullClass) {
            return new $nullClass();
        }

        // Unreachable if $nullObjects is kept in sync with contracts.
        // Defensive: cache null to avoid repeated filter calls.
        self::$cache[$filter] = null;
        return null;
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
