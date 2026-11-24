<?php
/**
 * Bytes that ProtoReader will not read: malformed protobuf, or a field the
 * caller's schema does not allow. Carries the CosmosTxVerdict reason.
 *
 * @package BCC\Core\Crypto\Cosmos
 */

namespace BCC\Core\Crypto\Cosmos;

if (!defined('ABSPATH')) {
    exit;
}

final class ProtoDecodeException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }
}
