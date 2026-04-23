<?php

namespace BCC\Core;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\Contracts\RecalcQueueReadInterface;
use BCC\Core\Contracts\ScoreContributorInterface;
use BCC\Core\Contracts\ScoreReadServiceInterface;
use BCC\Core\Contracts\PageOwnerResolverInterface;
use BCC\Core\Contracts\TrustHeaderDataInterface;
use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Contracts\WalletLinkReadInterface;
use BCC\Core\Contracts\WalletLinkWriteInterface;
use BCC\Core\Contracts\OnchainDataReadInterface;
use BCC\Core\Contracts\TrendingDataInterface;
use BCC\Core\Contracts\WalletSignalWriteInterface;
use BCC\Core\Contracts\WalletVerificationReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceLocator
{
    /** @var array<string, mixed> Memoized service instances, keyed by filter name. */
    private static array $cache = [];

    /** @var bool Whether the service cache is frozen (after plugins_loaded). */
    private static bool $frozen = false;

    /**
     * Allowlist of concrete classes permitted to provide each contract.
     *
     * Only classes listed here will be accepted from apply_filters().
     * Any other class — even if it implements the interface — is rejected
     * and logged. This prevents rogue plugins from hijacking trust services.
     *
     * To add a new legitimate provider, add its FQCN to the appropriate
     * contract key below AND register the filter in the provider plugin.
     *
     * @var array<class-string, list<string>>
     */
    private static array $allowedProviders = [
        DisputeAdjudicationInterface::class    => ['BCC\\Trust\\Application\\Disputes\\DisputeAdjudicationService'],
        TrustReadServiceInterface::class       => ['BCC\\Trust\\Application\\TrustReadService'],
        ScoreContributorInterface::class       => ['BCC\\Trust\\Application\\ScoreContributorService'],
        ScoreReadServiceInterface::class       => ['BCC\\Trust\\Application\\ScoreReadService'],
        TrustHeaderDataInterface::class        => ['BCC\\Trust\\Integration\\PeepSoIntegration'],
        PageOwnerResolverInterface::class      => ['BCC\\Trust\\Services\\PageOwnerResolver'],
        WalletVerificationReadInterface::class => ['BCC\\Trust\\Application\\WalletVerificationReadService'],
        WalletLinkReadInterface::class         => ['BCC\\Onchain\\Services\\WalletLinkReadService'],
        WalletLinkWriteInterface::class        => ['BCC\\Onchain\\Services\\WalletLinkWriteService'],
        OnchainDataReadInterface::class        => ['BCC\\Onchain\\Services\\OnchainDataReadService'],
        WalletSignalWriteInterface::class      => ['BCC\\Onchain\\Services\\WalletSignalWriteService'],
        TrendingDataInterface::class           => ['BCC\\Trust\\Application\\TrendingDataService'],
        RecalcQueueReadInterface::class        => ['BCC\\Trust\\Application\\RecalcQueueReadService'],
    ];

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
        WalletSignalWriteInterface::class      => \BCC\Core\NullServices\NullWalletSignalWrite::class,
        OnchainDataReadInterface::class        => \BCC\Core\NullServices\NullOnchainDataRead::class,
        TrendingDataInterface::class           => \BCC\Core\NullServices\NullTrendingData::class,
        RecalcQueueReadInterface::class        => \BCC\Core\NullServices\NullRecalcQueueRead::class,
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

    public static function resolveWalletSignalWrite(): WalletSignalWriteInterface
    {
        return self::resolveOnce('bcc.resolve.wallet_signal_write', WalletSignalWriteInterface::class);
    }

    public static function resolveOnchainDataRead(): OnchainDataReadInterface
    {
        return self::resolveOnce('bcc.resolve.onchain_data_read', OnchainDataReadInterface::class);
    }

    public static function resolveTrendingData(): TrendingDataInterface
    {
        return self::resolveOnce('bcc.resolve.trending_data', TrendingDataInterface::class);
    }

    public static function resolveRecalcQueueRead(): RecalcQueueReadInterface
    {
        return self::resolveOnce('bcc.resolve.recalc_queue_read', RecalcQueueReadInterface::class);
    }

    /**
     * Map of contract class → filter name.
     *
     * MAINTENANCE NOTE: this map MUST stay in sync with the resolve*()
     * methods above. If you add/rename a resolve method, update this
     * map too. Used by hasRealService() to look up cached instances.
     *
     * @var array<class-string, non-empty-string>
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
        WalletSignalWriteInterface::class      => 'bcc.resolve.wallet_signal_write',
        OnchainDataReadInterface::class        => 'bcc.resolve.onchain_data_read',
        TrendingDataInterface::class           => 'bcc.resolve.trending_data',
        RecalcQueueReadInterface::class        => 'bcc.resolve.recalc_queue_read',
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
        // The return value is intentionally discarded — we only care about
        // populating self::$cache so the array_key_exists check below works.
        if (!array_key_exists($filter, self::$cache)) {
            /** @var class-string $contract */
            self::resolveOnce($filter, $contract);
        }

        // If the filter resolved to a real service, it's in the cache.
        // NullObjects are NOT cached (by design in resolveOnce), so a
        // cache miss here means only a NullObject was available.
        return array_key_exists($filter, self::$cache);
    }

    /**
     * Freeze the service cache after plugins_loaded.
     *
     * Once frozen, resolved real services are locked in and cannot be
     * replaced by late-registered filters. NullObject fallbacks can
     * still be promoted to real services until frozen.
     *
     * Call this from the main plugin boot:
     *   add_action('plugins_loaded', [ServiceLocator::class, 'freeze'], PHP_INT_MAX);
     */
    public static function freeze(): void
    {
        self::$frozen = true;
    }

    /**
     * Verify that a resolved service is from an allowed provider class.
     *
     * @param object       $service  The resolved service instance.
     * @param class-string $contract The contract it claims to implement.
     * @return bool True if the service class is in the allowlist.
     */
    private static function isAllowedProvider(object $service, string $contract): bool
    {
        $allowed = self::$allowedProviders[$contract] ?? [];
        $class   = get_class($service);

        // SECURITY: Exact class match only — subclasses are NOT accepted.
        // Accepting subclasses would allow a rogue plugin to extend an
        // allowed class and override trust-critical methods (e.g.,
        // isSuspended, getEligiblePanelistUserIds) while passing the
        // allowlist check. Only the explicitly registered class names
        // from trusted BCC plugins are permitted.
        foreach ($allowed as $fqcn) {
            if ($class === $fqcn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a service via its filter hook, caching the result for the
     * lifetime of the request.
     *
     * Security: After apply_filters(), the resolved service is checked
     * against the $allowedProviders allowlist. Unrecognised implementations
     * are rejected with a logged warning and the NullObject fallback is
     * returned instead. This prevents rogue plugins from hijacking
     * trust-critical services.
     *
     * The cache is NOT populated with the NullObject so that a late-
     * loading plugin's filter can still provide the real implementation
     * on the next call within the same request (until freeze() is called).
     *
     * @template T of object
     * @param non-empty-string $filter   The filter hook name.
     * @param class-string<T> $contract The interface the resolved service must implement.
     * @return T
     */
    private static function resolveOnce(string $filter, string $contract)
    {
        if (array_key_exists($filter, self::$cache)) {
            $cached = self::$cache[$filter];
            if (!$cached instanceof $contract) {
                // Defensive: cache is only populated after instanceof checks
                // below, so this branch is unreachable. Guard against future
                // regressions in the caching logic.
                throw new \LogicException(
                    "Cached service for {$filter} does not implement {$contract}"
                );
            }
            return $cached;
        }

        if (self::$frozen) {
            // Frozen: no real service was registered before plugins_loaded.
            // Return NullObject — do NOT call apply_filters() again.
            $nullClass = self::$nullObjects[$contract] ?? null;
            if ($nullClass) {
                $null = new $nullClass();
                if (!$null instanceof $contract) {
                    throw new \LogicException(
                        "NullObject {$nullClass} does not implement contract {$contract}"
                    );
                }
                self::$cache[$filter] = $null;
                return $null;
            }
            throw new \LogicException("No NullObject registered for contract: {$contract}");
        }

        $service = apply_filters($filter, null);

        if ($service instanceof $contract) {
            // Enforce allowlist: reject unknown implementation classes.
            if (!self::isAllowedProvider($service, $contract)) {
                $class = get_class($service);
                Log\Logger::error('[ServiceLocator] REJECTED unrecognised provider', [
                    'filter'   => $filter,
                    'contract' => $contract,
                    'class'    => $class,
                ]);

                // Fall through to NullObject — do NOT cache the rogue service.
                $service = null;
            } else {
                self::$cache[$filter] = $service;
                return $service;
            }
        } elseif ($service !== null) {
            // Non-null but wrong type — log and reject.
            Log\Logger::error('[ServiceLocator] Filter returned wrong type', [
                'filter'   => $filter,
                'contract' => $contract,
                'type'     => is_object($service) ? get_class($service) : gettype($service),
            ]);
        }

        // No real provider available. Return NullObject fallback but do NOT
        // cache it — a plugin that loads later in this request should still
        // be able to provide the real implementation on the next resolve call.
        $nullClass = self::$nullObjects[$contract] ?? null;

        if ($nullClass) {
            $null = new $nullClass();
            if (!$null instanceof $contract) {
                throw new \LogicException(
                    "NullObject {$nullClass} does not implement contract {$contract}"
                );
            }
            return $null;
        }

        // Unreachable if $nullObjects is kept in sync with contracts.
        throw new \LogicException(
            "[ServiceLocator] No NullObject registered for contract: {$contract}"
        );
    }

}
