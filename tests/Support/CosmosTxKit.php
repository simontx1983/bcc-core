<?php

declare(strict_types=1);

namespace BCC\Core\Tests\Support;

use BCC\Core\Crypto\Bech32;
use PHPUnit\Framework\Assert;

/**
 * Builds and signs synthetic Cosmos transactions for CosmosTxVerifier's
 * shape-rule tests, INDEPENDENTLY of the verifier: its own protobuf
 * encoder, its own SignDoc / StdSignDoc construction (json_encode over
 * sorted keys), and an OpenSSL-generated secp256k1 key. Every negative
 * vector is re-signed, so the signature is valid and the one rule under
 * test is what refuses it.
 *
 * (Ethermint keys sign Keccak-256 digests, which OpenSSL cannot sign from
 * PHP; the ethsecp256k1 paths are covered by the real Injective vectors.)
 */
final class CosmosTxKit
{
    public const CHAIN_ID = 'testchain-1';
    public const ACCOUNT  = 4242;
    public const N_HEX    = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /** @var \OpenSSLAsymmetricKey */
    public $key;
    public string $pubKey;
    public string $addressBytes;

    /** @param \OpenSSLAsymmetricKey $key */
    private function __construct($key, string $pubKey)
    {
        $this->key          = $key;
        $this->pubKey       = $pubKey;
        $this->addressBytes = hash('ripemd160', hash('sha256', $pubKey, true), true);
    }

    /** A fresh key, or null where this OpenSSL build cannot make one. */
    public static function create(): ?self
    {
        $key = self::makeKey();
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        return new self($key, ((ord($y[31]) % 2 === 0) ? "\x02" : "\x03") . $x);
    }

    public function address(string $hrp = 'cosmos'): string
    {
        return Bech32::encode($hrp, $this->addressBytes);
    }

    public function valoper(string $hrp = 'cosmosvaloper'): string
    {
        return Bech32::encode($hrp, $this->addressBytes);
    }

    // ── protobuf encoding ───────────────────────────────────────────────

    public static function varint(int $v): string
    {
        $out = '';
        while ($v > 0x7f) {
            $out .= chr(($v & 0x7f) | 0x80);
            $v >>= 7;
        }
        return $out . chr($v);
    }

    public static function bytesField(int $field, string $bytes): string
    {
        return self::varint(($field << 3) | 2) . self::varint(strlen($bytes)) . $bytes;
    }

    public static function varintField(int $field, int $v): string
    {
        return self::varint($field << 3) . self::varint($v);
    }

    public static function any(string $typeUrl, string $value): string
    {
        return self::bytesField(1, $typeUrl) . self::bytesField(2, $value);
    }

    /** @param list<array{string, string}> $coins [denom, amount] */
    public static function coins(int $field, array $coins): string
    {
        $out = '';
        foreach ($coins as [$denom, $amount]) {
            $out .= self::bytesField($field, self::bytesField(1, $denom) . self::bytesField(2, $amount));
        }
        return $out;
    }

    /** @param list<array{string, string}> $amount */
    public static function msgSend(string $from, string $to, array $amount = [['uatom', '1']]): string
    {
        return self::any(
            '/cosmos.bank.v1beta1.MsgSend',
            self::bytesField(1, $from) . self::bytesField(2, $to) . self::coins(3, $amount)
        );
    }

    public static function secpKeyAny(string $pubKey, string $type = '/cosmos.crypto.secp256k1.PubKey'): string
    {
        return self::any($type, self::bytesField(1, $pubKey));
    }

    public static function modeInfo(int $mode): string
    {
        return self::bytesField(1, self::varintField(1, $mode));
    }

    // ── transactions ────────────────────────────────────────────────────

    /**
     * A signed self-send from this key, with any part overridden.
     *
     * @param array<string, mixed> $o
     * @return array{raw: string, hash: string}
     */
    public function tx(array $o = []): array
    {
        $from   = (string) ($o['from'] ?? $this->address());
        $to     = (string) ($o['to'] ?? $from);
        $memo   = (string) ($o['memo'] ?? 'BCC: claim validator page test. Code ABCD2345');
        /** @var list<array{string, string}> $amount */
        $amount = $o['amount'] ?? [['uatom', '1']];
        $mode   = (int) ($o['mode'] ?? 1);
        $seq    = (int) ($o['sequence'] ?? 7);
        /** @var list<array{string, string}> $feeCoins */
        $feeCoins = $o['fee'] ?? [['uatom', '5000']];
        $gas      = (int) ($o['gas'] ?? 200000);
        $chainId  = (string) ($o['chain_id'] ?? self::CHAIN_ID);
        $acct     = (int) ($o['account_number'] ?? self::ACCOUNT);

        /** @var list<string> $msgs */
        $msgs = $o['msgs'] ?? [self::msgSend($from, $to, $amount)];
        $body = '';
        foreach ($msgs as $m) {
            $body .= self::bytesField(1, $m);
        }
        if ($memo !== '') {
            $body .= self::bytesField(2, $memo);
        }
        if (isset($o['timeout_height'])) {
            $body .= self::varintField(3, (int) $o['timeout_height']);
        }
        $body .= (string) ($o['body_extra'] ?? '');

        $keyAny     = array_key_exists('key_any', $o) ? $o['key_any'] : self::secpKeyAny($this->pubKey);
        $modeInfo   = (string) ($o['mode_info'] ?? self::modeInfo($mode));
        $signerInfo = (is_string($keyAny) ? self::bytesField(1, $keyAny) : '')
            . self::bytesField(2, $modeInfo)
            . ($seq !== 0 ? self::varintField(3, $seq) : '');

        $fee = self::coins(1, $feeCoins) . self::varintField(2, $gas)
            . (isset($o['payer']) ? self::bytesField(3, (string) $o['payer']) : '')
            . (isset($o['granter']) ? self::bytesField(4, (string) $o['granter']) : '');

        $signerCount = (int) ($o['signer_infos'] ?? 1);
        $auth        = str_repeat(self::bytesField(1, $signerInfo), $signerCount)
            . self::bytesField(2, $fee)
            . (string) ($o['auth_extra'] ?? '');

        if ($mode === 127) {
            $signBytes = isset($o['amino_doc']) ? (string) $o['amino_doc'] : self::aminoDoc($chainId, $acct, $seq, $feeCoins, $gas, $memo, $from, $to, $amount);
        } else {
            $signBytes = self::bytesField(1, $body) . self::bytesField(2, $auth) . self::bytesField(3, $chainId)
                . ($acct !== 0 ? self::varintField(4, $acct) : '');
        }
        $signer = $o['sign_with'] ?? $this;
        Assert::assertInstanceOf(self::class, $signer);
        $sig = $signer->sign($signBytes);
        if (($o['high_s'] ?? false) === true) {
            $sig = self::malleate($sig);
        }

        /** @var list<string> $sigs */
        $sigs = $o['signatures'] ?? [$sig];
        $raw  = self::bytesField(1, $body) . self::bytesField(2, $auth);
        foreach ($sigs as $s) {
            $raw .= self::bytesField(3, $s);
        }
        $raw .= (string) ($o['raw_extra'] ?? '');

        return ['raw' => $raw, 'hash' => strtoupper(hash('sha256', $raw))];
    }

    /**
     * The amino StdSignDoc, built with json_encode over keys in sorted order.
     *
     * @param list<array{string, string}> $fee
     * @param list<array{string, string}> $amount
     */
    public static function aminoDoc(string $chainId, int $acct, int $seq, array $fee, int $gas, string $memo, string $from, string $to, array $amount): string
    {
        $coins = static fn (array $list): array => array_map(
            static fn (array $c): array => ['amount' => $c[1], 'denom' => $c[0]],
            $list
        );
        $doc = [
            'account_number' => (string) $acct,
            'chain_id'       => $chainId,
            'fee'            => ['amount' => $coins($fee), 'gas' => (string) $gas],
            'memo'           => $memo,
            'msgs'           => [[
                'type'  => 'cosmos-sdk/MsgSend',
                'value' => ['amount' => $coins($amount), 'from_address' => $from, 'to_address' => $to],
            ]],
            'sequence'       => (string) $seq,
        ];
        $json = json_encode($doc, JSON_UNESCAPED_SLASHES);
        Assert::assertIsString($json);
        return $json;
    }

    // ── signing ─────────────────────────────────────────────────────────

    /** ECDSA over SHA-256 of $message; compact r||s, low-S. */
    public function sign(string $message): string
    {
        $der = '';
        Assert::assertTrue(openssl_sign($message, $der, $this->key, OPENSSL_ALGO_SHA256));
        return self::normalizeLowS(self::derToRs($der));
    }

    public static function malleate(string $rs): string
    {
        $n = gmp_init(self::N_HEX, 16);
        $s = gmp_sub($n, gmp_init(bin2hex(substr($rs, 32)), 16));
        return substr($rs, 0, 32) . str_pad(gmp_export($s), 32, "\x00", STR_PAD_LEFT);
    }

    private static function normalizeLowS(string $rs): string
    {
        $n = gmp_init(self::N_HEX, 16);
        $s = gmp_init(bin2hex(substr($rs, 32)), 16);
        if (gmp_cmp($s, gmp_div_q($n, 2)) > 0) {
            $s = gmp_sub($n, $s);
        }
        return substr($rs, 0, 32) . str_pad(gmp_export($s), 32, "\x00", STR_PAD_LEFT);
    }

    private static function derToRs(string $der): string
    {
        $pos = 2;
        $rLen = ord($der[$pos + 1]);
        $r    = substr($der, $pos + 2, $rLen);
        $pos += 2 + $rLen;
        $sLen = ord($der[$pos + 1]);
        $s    = substr($der, $pos + 2, $sLen);
        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * secp256k1 keygen. On Linux/CI the bare call works; on Windows PHP
     * builds openssl_pkey_new needs an explicit openssl.cnf.
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private static function makeKey()
    {
        $args = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1'];
        $key  = @openssl_pkey_new($args);
        if ($key !== false) {
            return $key;
        }
        foreach (array_filter([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf']) as $cnf) {
            if (is_file($cnf)) {
                $key = @openssl_pkey_new(['config' => $cnf] + $args);
                if ($key !== false) {
                    return $key;
                }
            }
        }
        return false;
    }
}
