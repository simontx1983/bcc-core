<?php
/**
 * Cosmos Signature Verifier
 *
 * Verifies a Cosmos wallet signature produced by Keplr's signAmino().
 * Uses OpenSSL for secp256k1 ECDSA verification.
 *
 * Requires: OpenSSL extension (standard on PHP).
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

class CosmosSignatureVerifier {

    public static function verify(
        string $message,
        string $signature,
        string $address,
        string $pubKeyB64,
        string $chainId = 'cosmoshub-4',
        string $signedDocJson = ''
    ): bool {

        if ($signedDocJson !== '') {
            $doc = json_decode($signedDocJson, true);
            if (!is_array($doc)) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-core] CosmosVerifier: could not decode signed_doc JSON', []);
                }
                return false;
            }
            if (($doc['memo'] ?? '') !== $message) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-core] CosmosVerifier: nonce mismatch in signed_doc memo', []);
                }
                return false;
            }
            $signDoc = self::canonicalJson($doc);
        } else {
            $signDoc = self::buildSignDoc($message, $address, $chainId);
        }

        $sigRaw = base64_decode($signature, true);
        if (!is_string($sigRaw) || strlen($sigRaw) !== 64) {
            return false;
        }

        $pubKeyRaw = base64_decode($pubKeyB64, true);
        if (!is_string($pubKeyRaw) || strlen($pubKeyRaw) !== 33) {
            return false;
        }

        $der = self::rsToAsn1($sigRaw);
        if ($der === null) {
            return false;
        }

        $ecKey = self::importCompressedPubKey($pubKeyRaw);
        if ($ecKey === false || $ecKey === null) {
            return false;
        }

        $result = openssl_verify($signDoc, $der, $ecKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private static function buildSignDoc(string $nonce, string $address, string $chainId): string {
        $doc = [
            'account_number' => '0',
            'chain_id'       => $chainId,
            'fee'            => [
                'amount' => [],
                'gas'    => '0',
            ],
            'memo'           => $nonce,
            'msgs'           => [],
            'sequence'       => '0',
        ];
        return self::canonicalJson($doc);
    }

    private static function canonicalJson(array $data): string {
        ksort($data);
        $parts = [];
        foreach ($data as $key => $value) {
            $encodedKey = json_encode((string) $key);
            if (is_array($value)) {
                if (empty($value)) {
                    $encodedValue = '[]';
                } elseif (array_keys($value) === range(0, count($value) - 1)) {
                    $items = [];
                    foreach ($value as $item) {
                        $items[] = is_array($item) ? self::canonicalJson($item) : json_encode($item);
                    }
                    $encodedValue = '[' . implode(',', $items) . ']';
                } else {
                    $encodedValue = self::canonicalJson($value);
                }
            } else {
                $encodedValue = json_encode($value);
            }
            $parts[] = $encodedKey . ':' . $encodedValue;
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function rsToAsn1(string $rs): ?string {
        if (strlen($rs) !== 64) {
            return null;
        }
        $r = substr($rs, 0, 32);
        $s = substr($rs, 32, 32);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) === 0) $r = "\x00";
        if (strlen($s) === 0) $s = "\x00";

        if (ord($r[0]) >= 0x80) $r = "\x00" . $r;
        if (ord($s[0]) >= 0x80) $s = "\x00" . $s;

        $rLen = strlen($r);
        $sLen = strlen($s);

        $rDer = "\x02" . chr($rLen) . $r;
        $sDer = "\x02" . chr($sLen) . $s;

        $seqLen = strlen($rDer) + strlen($sDer);

        return "\x30" . chr($seqLen) . $rDer . $sDer;
    }

    private static function importCompressedPubKey(string $compressed) {
        if (strlen($compressed) !== 33) {
            return null;
        }

        $oidEcPublicKey = "\x2a\x86\x48\xce\x3d\x02\x01";
        $oidSecp256k1   = "\x2b\x81\x04\x00\x0a";

        $algSeq = "\x30" . chr(2 + strlen($oidEcPublicKey) + 2 + strlen($oidSecp256k1))
                . "\x06" . chr(strlen($oidEcPublicKey)) . $oidEcPublicKey
                . "\x06" . chr(strlen($oidSecp256k1))   . $oidSecp256k1;

        $bitString = "\x03" . chr(1 + strlen($compressed)) . "\x00" . $compressed;

        $spki = "\x30" . chr(strlen($algSeq) + strlen($bitString)) . $algSeq . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spki), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

        return openssl_get_publickey($pem);
    }
}
