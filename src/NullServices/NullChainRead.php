<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\ChainReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Returns empty/null chain-config shapes so downstream card-rendering and
 * group/blog chain-tag resolution don't crash when the onchain read-side is
 * offline. Sustained activation = chain-bearing surfaces render without chains.
 *
 * NOT instrumented with a DegradationMetric (mirrors NullRecalcQueueRead):
 * registering a new `null_chain_read` subsystem would require touching the
 * canonical subsystem map in bcc-core.php plus the parity docs, which is out
 * of scope for the read-surface promotion. When bcc-trust is offline, the
 * site-wide `activation` signal is already raised by the sibling NullServices.
 *
 * @phpstan-import-type ChainRow from ChainReadInterface
 */
final class NullChainRead implements ChainReadInterface
{
    /** @return ChainRow|null */
    public function getBySlug(string $slug): ?object
    {
        return null;
    }

    /** @return ChainRow|null */
    public function getById(int $chainId): ?object
    {
        return null;
    }

    /** @return list<ChainRow> */
    public function getActive(?string $chainType = null): array
    {
        return [];
    }

    public function resolveId(string $slug): ?int
    {
        return null;
    }

    /**
     * @param list<int> $groupIds
     * @return array<int, string>
     */
    public function resolveSlugsForGroups(array $groupIds): array
    {
        return [];
    }
}
