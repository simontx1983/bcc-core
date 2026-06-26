<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only contract for retrieving chain-registry config across plugin boundaries.
 *
 * Consumer plugins (e.g. bcc-trust's Domain/Core) call this interface instead of
 * directly accessing bcc-trust's Onchain ChainRepository. bcc-trust provides the
 * implementation (a thin adapter over the storage-level ChainRepository).
 *
 * This is a DIFFERENT concern from {@see OnchainDataReadInterface}, which exposes
 * project-scoped validator/collection AGGREGATES — this one exposes chain CONFIG
 * rows (slug/id/active/group-tag resolution).
 *
 * The ChainRow shape mirrors ChainRepository's @phpstan-type ChainRow exactly
 * (explicit COLUMNS list, all values as DB-string|null). Replicated here rather
 * than imported so the contract carries no dependency on the bcc-trust impl.
 *
 * @phpstan-type ChainRow object{
 *     id: string,
 *     slug: string,
 *     name: string,
 *     chain_type: string,
 *     chain_id_hex: string|null,
 *     rpc_url: string|null,
 *     rest_url: string|null,
 *     explorer_url: string|null,
 *     native_token: string|null,
 *     decimals: string,
 *     bech32_prefix: string|null,
 *     icon_url: string|null,
 *     color: string|null,
 *     marketplace_template: string|null,
 *     is_testnet: string,
 *     is_active: string,
 *     created_at: string
 * }
 */
interface ChainReadInterface
{
    /** @return ChainRow|null */
    public function getBySlug(string $slug): ?object;

    /** @return ChainRow|null */
    public function getById(int $chainId): ?object;

    /** @return list<ChainRow> */
    public function getActive(?string $chainType = null): array;

    public function resolveId(string $slug): ?int;

    /**
     * Map group_id → chain slug for the given peepso-group post IDs.
     *
     * @param list<int> $groupIds
     * @return array<int, string>
     */
    public function resolveSlugsForGroups(array $groupIds): array;
}
