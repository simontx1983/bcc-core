<?php
/**
 * Cosmos account address from a public key: the ONE derivation rule shared
 * by the ADR-036 verifier (CosmosSignatureVerifier) and the transaction
 * verifier (Cosmos\CosmosTxVerifier).
 *
 *   - Standard Cosmos chains: RIPEMD-160(SHA-256(33-byte compressed key)).
 *   - Ethermint chains (Injective, keys of type ethsecp256k1):
 *     Keccak-256(64-byte uncompressed key)[12..32], Ethereum's derivation.
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class CosmosAddress
{
    /**
     * Bech32 account HRPs whose chains derive the account address the
     * Ethermint (Ethereum) way, and whose accounts hold ethsecp256k1 keys.
     *
     * Deriving the standard Cosmos address for these chains yields the
     * WRONG address. Other Ethermint/EVM-Cosmos chains (Evmos `evmos`,
     * Dymension `dym`, etc.) share this derivation; add their HRPs here
     * if/when onboarded.
     *
     * @var array<string, true>
     */
    public const ETHERMINT_HRPS = [
        'inj' => true,
    ];

    public static function isEthermintHrp(string $hrp): bool
    {
        return isset(self::ETHERMINT_HRPS[$hrp]);
    }

    /**
     * The 20-byte account address of a 33-byte compressed secp256k1 key,
     * by the standard or the Ethermint rule.
     *
     * ⚠ The CALLER validates the key. The standard rule hashes whatever it
     * is given (as the ADR-036 verifier always has; its verify() checks the
     * length first); the Ethermint rule must decompress the point, so it is
     * null for anything that is not a valid compressed key.
     */
    public static function bytesFromPubKey(string $compressedPubKey, bool $ethermint): ?string
    {
        if (!$ethermint) {
            return hash('ripemd160', hash('sha256', $compressedPubKey, true), true);
        }

        $uncompressed = Secp256k1::decompress($compressedPubKey);
        if ($uncompressed === null) {
            return null;
        }
        // Keccak256::hash(..., true) returns 32 raw bytes; keep the last 20.
        $keccak = Keccak256::hash($uncompressed, true);
        return strlen($keccak) === 32 ? substr($keccak, 12) : null;
    }
}
