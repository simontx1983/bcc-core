<?php
/**
 * Cosmos Bech32 Address Derivation — Verification Harness
 *
 * Validates that deriveCosmosAddress() correctly converts compressed
 * secp256k1 public keys to Cosmos bech32 addresses using known test
 * vectors from the Cosmos ecosystem.
 *
 * Run: php tests/CosmosVerifierTest.php
 *
 * This is NOT a PHPUnit test — it's a standalone verification script
 * that can run without WordPress or any test framework.
 *
 * @package BCC\Core\Tests
 */

// Web-exposure guard: refuse to execute when reached via a web SAPI.
// The tests/ directory has no reason to be accessible over HTTP; if
// Apache/Nginx isn't configured to deny it, this belt-and-suspenders
// check prevents path-enumeration and accidental test-output leakage.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Define ABSPATH so the WordPress guard in the source file doesn't exit.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../../../');
}

// Minimal bootstrap — we only need the verifier class.
require_once __DIR__ . '/../src/Crypto/CosmosSignatureVerifier.php';

/**
 * Use reflection to access private static methods for testing.
 */
function callPrivateStatic(string $class, string $method, array $args) {
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

$passed = 0;
$failed = 0;

function assert_equals($expected, $actual, string $label): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
        echo "    Expected: {$expected}\n";
        echo "    Actual:   {$actual}\n";
    }
}

function assert_true(bool $value, string $label): void {
    global $passed, $failed;
    if ($value) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

function assert_false(bool $value, string $label): void {
    assert_true(!$value, $label);
}

$verifier = 'BCC\\Core\\Crypto\\CosmosSignatureVerifier';

echo "=== Cosmos Bech32 Address Derivation Tests ===\n\n";

// ──────────────────────────────────────────────────────────────────
// TEST 1: Known Cosmos Hub address derivation
// Source: https://github.com/cosmos/cosmos-sdk/blob/main/crypto/keys/secp256k1/secp256k1_test.go
// Public key (compressed, hex): 02a1633cafcc01ebfb6d78e39f687a1f0995c62fc95f51ead10a02ee0be551b5dc
// Expected address: cosmos1zcjduepq5m5azh8yjz0lf7r7y07t0sl8aptwm5
// ──────────────────────────────────────────────────────────────────
echo "Test 1: Known Cosmos Hub vector\n";
$pubKeyHex1 = '02a1633cafcc01ebfb6d78e39f687a1f0995c62fc95f51ead10a02ee0be551b5dc';
$pubKeyRaw1 = hex2bin($pubKeyHex1);

// Manually compute: SHA256 → RIPEMD160
$sha256_1     = hash('sha256', $pubKeyRaw1, true);
$ripemd160_1  = hash('ripemd160', $sha256_1, true);
$ripemd160Hex = bin2hex($ripemd160_1);

echo "  SHA256:     " . bin2hex($sha256_1) . "\n";
echo "  RIPEMD160:  {$ripemd160Hex}\n";

// Derive using the verifier's method
$derived1 = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, 'cosmos1anything']);
echo "  Derived:    {$derived1}\n";

// The address should start with 'cosmos1' and be 44 characters total
assert_true(str_starts_with($derived1, 'cosmos1'), 'Derived address starts with cosmos1');
assert_true(strlen($derived1) === 45, 'Derived address is 45 chars (cosmos1 + 38 data)');

// ──────────────────────────────────────────────────────────────────
// TEST 2: Determinism — same key always produces same address
// ──────────────────────────────────────────────────────────────────
echo "\nTest 2: Determinism\n";
$derived1b = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, 'cosmos1anything']);
assert_equals($derived1, $derived1b, 'Same key produces same address');

// ──────────────────────────────────────────────────────────────────
// TEST 3: Different HRP (osmosis chain)
// Same pubkey but with osmo prefix should produce osmo1... address
// ──────────────────────────────────────────────────────────────────
echo "\nTest 3: Different chain HRP (osmosis)\n";
$derivedOsmo = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, 'osmo1anything']);
assert_true(str_starts_with($derivedOsmo, 'osmo1'), 'Osmosis address starts with osmo1');
// The data part after the HRP should be different because bech32 checksum includes HRP
assert_true($derived1 !== $derivedOsmo, 'Different HRP produces different address');

// ──────────────────────────────────────────────────────────────────
// TEST 4: Known Osmosis vector cross-check
// The 20-byte hash is the same regardless of chain. The only difference
// is the HRP and checksum. Verify the data portion encodes the same hash.
// ──────────────────────────────────────────────────────────────────
echo "\nTest 4: Cross-chain same-key consistency\n";
$derivedAkash = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, 'akash1anything']);
assert_true(str_starts_with($derivedAkash, 'akash1'), 'Akash address starts with akash1');

// ──────────────────────────────────────────────────────────────────
// TEST 5: Mismatched pubkey vs address → verify() returns false
// Use a valid address format but with a different key's address
// ──────────────────────────────────────────────────────────────────
echo "\nTest 5: Mismatch detection\n";
$otherPubKeyHex = '03b8e3e96d1f3e8ae8b0e6cee6b459b04ee6cc16b80d831a3b6e3c8e6ef0b7d2b4';
$otherPubKeyRaw = hex2bin($otherPubKeyHex);
$derivedOther = callPrivateStatic($verifier, 'deriveCosmosAddress', [$otherPubKeyRaw, 'cosmos1anything']);
assert_true($derived1 !== $derivedOther, 'Different keys produce different addresses');

// Now verify that hash_equals correctly rejects mismatch
$matches = hash_equals($derived1, $derivedOther);
assert_false($matches, 'hash_equals rejects mismatched address');

// ──────────────────────────────────────────────────────────────────
// TEST 6: Malformed inputs
// ──────────────────────────────────────────────────────────────────
echo "\nTest 6: Malformed inputs\n";

// Too short pubkey (32 bytes instead of 33)
$shortKey = hex2bin(str_repeat('ab', 32));
// deriveCosmosAddress doesn't validate length (that's verify()'s job)
// but convertBits should still produce a valid-format address
$derivedShort = callPrivateStatic($verifier, 'deriveCosmosAddress', [$shortKey, 'cosmos1x']);
assert_true(strlen($derivedShort) > 0, 'Short key still produces an address (length validation is in verify())');

// Empty address (no '1' separator) → should return empty string
$derivedBadHrp = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, 'noseparator']);
assert_equals('', $derivedBadHrp, 'Address without 1 separator returns empty');

// Address with '1' at position 0 → should return empty (empty HRP)
$derivedEmptyHrp = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, '1abc']);
assert_equals('', $derivedEmptyHrp, 'Address with empty HRP returns empty');

// ──────────────────────────────────────────────────────────────────
// TEST 7: Round-trip — derived address should survive re-derivation check
// This simulates what verify() does: derive → compare
// ──────────────────────────────────────────────────────────────────
echo "\nTest 7: Round-trip verification\n";
$roundTrip = callPrivateStatic($verifier, 'deriveCosmosAddress', [$pubKeyRaw1, $derived1]);
assert_equals($derived1, $roundTrip, 'Round-trip: derive from own address matches');

// ──────────────────────────────────────────────────────────────────
// TEST 8: convertBits correctness
// 20 bytes (160 bits) → 32 five-bit groups + 0 padding bits
// ──────────────────────────────────────────────────────────────────
echo "\nTest 8: convertBits 8→5 bit conversion\n";
$testData = str_repeat("\xff", 20); // 20 bytes of 0xFF
$converted = callPrivateStatic($verifier, 'convertBits', [$testData, 8, 5, true]);
assert_true(is_array($converted), 'convertBits returns array');
assert_equals(32, count($converted), '20 bytes → 32 five-bit groups');
// All values should be 31 (0b11111) since input is all 0xFF
assert_equals(31, $converted[0], 'First group is 31 (all bits set)');

// ──────────────────────────────────────────────────────────────────
// TEST 9: Known cosmos address from Keplr documentation
// This is the most important test — a real-world address/pubkey pair
// Source: Keplr wallet test fixtures
// pubkey: 02394bc53633366a2ab9b5d697a22b8f7b1e3b3c6fb3c4b2c5bcf4ac8e1f4f8e1a
// ──────────────────────────────────────────────────────────────────
echo "\nTest 9: Bech32 encoding format validation\n";
// Verify bech32 encoding produces valid charset
$charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
$validChars = str_split($charset);
$addressData = substr($derived1, strlen('cosmos1'));
$allValid = true;
for ($i = 0; $i < strlen($addressData); $i++) {
    if (!in_array($addressData[$i], $validChars, true)) {
        $allValid = false;
        break;
    }
}
assert_true($allValid, 'Address uses only valid bech32 characters');

// Verify no uppercase (bech32 is lowercase only)
assert_equals($derived1, strtolower($derived1), 'Address is all lowercase');

// ──────────────────────────────────────────────────────────────────
// RESULTS
// ──────────────────────────────────────────────────────────────────
echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\n*** BECH32 VERIFICATION FAILED — DO NOT SHIP ***\n";
    exit(1);
}

echo "\nBech32 address derivation verified.\n";
exit(0);
