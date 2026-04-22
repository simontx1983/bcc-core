<?php

namespace BCC\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared DTO invariant helpers for the BCC ecosystem.
 *
 * Every check:
 *   - takes the value plus `{dto, field}` labels for consistent error copy,
 *   - throws `\LogicException` on failure (fail-fast, not fail-soft),
 *   - is a pure function — no I/O, no logging, no coercion, no defaults.
 *
 * Error-message shape is stable: `{DTO}: {field} must be {rule} (got {value})`.
 * Arg order (value, dto, field) mirrors the sentence structure — dto is the
 * prefix/header, field is the subject — so call sites read like the output.
 *
 * No coercion: helpers validate and throw; they never cast, default, or
 * "fix" input. If upstream sent garbage, the DTO fails to construct — that's
 * the point.
 *
 * Class is non-final by design so plugin-specific assert classes
 * (DisputeAsserts, etc.) can extend it to add domain invariants while
 * inheriting the shared primitive checks.
 */
class DTOAssert
{
    public static function positiveInt(int $value, string $dto, string $field): void
    {
        if ($value <= 0) {
            throw new \LogicException("{$dto}: {$field} must be positive (got {$value})");
        }
    }

    /**
     * Positive-int check that tolerates null as a legitimate "absent FK" state.
     * Use for optional foreign keys; the value is either unset or positive —
     * zero and negatives are always invalid.
     */
    public static function positiveIntOrNull(?int $value, string $dto, string $field): void
    {
        if ($value !== null && $value <= 0) {
            throw new \LogicException("{$dto}: {$field} must be positive when present (got {$value})");
        }
    }

    public static function nonNegativeInt(int $value, string $dto, string $field): void
    {
        if ($value < 0) {
            throw new \LogicException("{$dto}: {$field} must be non-negative (got {$value})");
        }
    }

    public static function nonEmptyString(string $value, string $dto, string $field): void
    {
        if ($value === '') {
            throw new \LogicException("{$dto}: {$field} must be non-empty");
        }
    }

    /**
     * Finite float (no NaN, no INF). Use for trust scores, weights, fraud
     * probabilities etc. The domain may permit negatives (score diffs), so
     * this does not constrain the sign.
     */
    public static function finiteFloat(float $value, string $dto, string $field): void
    {
        if (!is_finite($value)) {
            throw new \LogicException("{$dto}: {$field} must be finite (got {$value})");
        }
    }

    /**
     * Non-negative finite float. Use for magnitudes: weights, counts, ratios
     * that cannot drop below zero by domain rule.
     */
    public static function nonNegativeFloat(float $value, string $dto, string $field): void
    {
        if (!is_finite($value) || $value < 0.0) {
            throw new \LogicException("{$dto}: {$field} must be finite and non-negative (got {$value})");
        }
    }

    /**
     * Generic allow-list check. Emits a predictable error message listing
     * the permitted values — useful for subset validations where the
     * canonical domain enum's assert() is too permissive.
     *
     * @param list<string> $allowed
     */
    public static function enum(string $value, array $allowed, string $dto, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            $list = implode('|', $allowed);
            throw new \LogicException("{$dto}: {$field} must be one of {$list} (got '{$value}')");
        }
    }

    /**
     * @param list<int> $allowed
     */
    public static function intEnum(int $value, array $allowed, string $dto, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            $list = implode('|', array_map('strval', $allowed));
            throw new \LogicException("{$dto}: {$field} must be one of {$list} (got {$value})");
        }
    }

    /**
     * Require a parseable, ABSOLUTE datetime string. strtotime() accepts MySQL
     * DATETIME, ISO-8601, RFC-2822, etc. — plus a variety of relative forms
     * like 'now', '+1 day', '-3 hours' that should NEVER appear in a DTO:
     *
     *   - A DTO is a snapshot of persisted state, not an instruction.
     *   - strtotime('now') returns the current timestamp — silently valid
     *     but semantically nonsense as a 'created_at' value.
     *   - Relative strings in a DB row indicate upstream string interpolation
     *     leaking into a timestamp column; we want that to fail-fast.
     *
     * Rejects '', garbage, MySQL's '0000-00-00 00:00:00' sentinel, AND the
     * common relative prefixes. Absolute timestamps pass through unchanged.
     */
    public static function datetime(string $value, string $dto, string $field): void
    {
        if (strtotime($value) === false) {
            throw new \LogicException("{$dto}: {$field} must be a parseable datetime (got '{$value}')");
        }
        if (preg_match('/^(now|[+-]\d)/i', $value) === 1) {
            throw new \LogicException("{$dto}: {$field} must be an absolute datetime (got '{$value}')");
        }
    }

    /**
     * Same as datetime() but skips the check when the value is null.
     * Null is treated as a legitimate "not set yet" state, not an error.
     */
    public static function nullableDatetime(?string $value, string $dto, string $field): void
    {
        if ($value === null) {
            return;
        }
        self::datetime($value, $dto, $field);
    }
}
