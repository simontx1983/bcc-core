<?php

namespace BCC\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository-boundary row-extraction helpers.
 *
 * Converts raw `$wpdb->get_row(..., ARRAY_A)` / `get_results(..., ARRAY_A)`
 * scalars into strictly typed PHP values. Every helper throws on type
 * corruption — no silent coercion, no defaults.
 *
 * This is the single biggest type-safety surface in the BCC ecosystem:
 * a bug here miscompiles every repository identically. The contract below
 * is deliberately narrow and symmetric across helpers.
 *
 * ## Edge-case matrix (unsigned/digit helpers)
 *
 *   requireDigitInt    | optDigitInt
 *   ───────────────────┼─────────────────
 *   int   N            | N                  → passthrough (signed int accepted;
 *                                              domain sign-check belongs on the
 *                                              DTO field, not here)
 *   "123"              | "123"              → 123
 *   "00123"            | "00123"            → 123        (ctype_digit allows leading zeros)
 *   " 123" / "123 "    | " 123"             → THROW      (ctype_digit rejects whitespace)
 *   "-1" / "+1"        | "-1"               → THROW      (unsigned-only)
 *   "1e3" / "1.5"      | "1e3"              → THROW      (is_numeric edge cases)
 *   ""                 | ""                 → THROW
 *   null               | null               → THROW / null
 *   bool / array / obj | …                  → THROW
 *
 * ## Edge-case matrix (float helpers)
 *
 *   requireFloat   | optFloat
 *   ───────────────┼─────────────────
 *   int N          | N              → (float) N
 *   1.5            | 1.5            → 1.5
 *   NAN / INF      | NAN / INF      → THROW
 *   "1.5"          | "1.5"          → 1.5
 *   "1e3"          | "1e3"          → 1000.0         (is_numeric accepts scientific)
 *   "-1.5"         | "-1.5"         → -1.5           (signed float; domain sign-check belongs on DTO field)
 *   " 1.5" / "1.5 "| …              → 1.5            (PHP 8 is_numeric accepts wrapping ws; MySQL never emits)
 *   ""             | ""             → THROW
 *   null           | null           → THROW / null
 *
 * ## String / bool helpers
 *
 *   requireString: must be `is_string` (empty string allowed — MySQL DEFAULT ''
 *                   is a legitimate value for many VARCHAR columns). null, int,
 *                   float, bool all THROW.
 *   optString:     null → null; otherwise same as requireString.
 *   requireBool:   native bool, int 0/1, string "0"/"1". Anything else THROWS
 *                   — rules out truthy-but-wrong TINYINT corruption like 2.
 *
 * ## Design notes
 *
 *   - Error messages include `get_debug_type()` of the offending value so log
 *     output identifies type corruption at the row level.
 *   - Missing column throws a distinct message from type-mismatch so SQL-vs-schema
 *     drift is distinguishable from data corruption.
 *   - `LogicException` (not `RuntimeException`) because a row that violates
 *     this contract indicates a programming error (schema drift, manual DB edit,
 *     or repository SQL mismatch), not a transient failure.
 *
 * Class is non-final by design so plugin-specific row-assert classes can
 * extend it to add domain-specific extractors while inheriting the shared
 * primitive checks.
 */
class RowAssert
{
    /**
     * @param array<string, scalar|null> $row
     */
    public static function requireDigitInt(array $row, string $column): int
    {
        if (!array_key_exists($column, $row)) {
            throw new \LogicException("Missing column '{$column}' in row");
        }
        $value = $row[$column];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \LogicException(
            "Column '{$column}' is not a valid digit value: " . get_debug_type($value)
        );
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function optDigitInt(array $row, string $column): ?int
    {
        if (!array_key_exists($column, $row)) {
            return null;
        }
        $value = $row[$column];
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \LogicException(
            "Column '{$column}' is not null or digit value: " . get_debug_type($value)
        );
    }

    /**
     * Accepts a signed int or a string matching `[-]?\d+` (no leading zeros
     * restriction; MySQL TINYINT/INT columns legitimately produce values like
     * "-1"). Rejects floats, scientific notation, whitespace.
     *
     * @param array<string, scalar|null> $row
     */
    public static function requireSignedInt(array $row, string $column): int
    {
        if (!array_key_exists($column, $row)) {
            throw new \LogicException("Missing column '{$column}' in row");
        }
        $value = $row[$column];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new \LogicException(
            "Column '{$column}' is not a valid signed-int value: " . get_debug_type($value)
        );
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function requireFloat(array $row, string $column): float
    {
        if (!array_key_exists($column, $row)) {
            throw new \LogicException("Missing column '{$column}' in row");
        }
        $value = $row[$column];
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \LogicException("Column '{$column}' is a non-finite float");
            }
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            $f = (float) $value;
            if (!is_finite($f)) {
                throw new \LogicException("Column '{$column}' is a non-finite numeric string");
            }
            return $f;
        }
        throw new \LogicException(
            "Column '{$column}' is not a valid float value: " . get_debug_type($value)
        );
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function optFloat(array $row, string $column): ?float
    {
        if (!array_key_exists($column, $row)) {
            return null;
        }
        $value = $row[$column];
        if ($value === null) {
            return null;
        }
        return self::requireFloat($row, $column);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function requireString(array $row, string $column): string
    {
        if (!array_key_exists($column, $row)) {
            throw new \LogicException("Missing column '{$column}' in row");
        }
        $value = $row[$column];
        if (!is_string($value)) {
            throw new \LogicException(
                "Column '{$column}' is not a string: " . get_debug_type($value)
            );
        }
        return $value;
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function optString(array $row, string $column): ?string
    {
        if (!array_key_exists($column, $row)) {
            return null;
        }
        $value = $row[$column];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \LogicException(
                "Column '{$column}' has non-null, non-string value: " . get_debug_type($value)
            );
        }
        return $value;
    }

    /**
     * Accepts native bool or the MySQL TINYINT(1) forms: 0/1 (int) or '0'/'1'
     * (string). Anything else is corruption.
     *
     * @param array<string, scalar|null> $row
     */
    public static function requireBool(array $row, string $column): bool
    {
        if (!array_key_exists($column, $row)) {
            throw new \LogicException("Missing column '{$column}' in row");
        }
        $value = $row[$column];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if ($value === '0' || $value === '1') {
            return $value === '1';
        }
        throw new \LogicException(
            "Column '{$column}' is not a valid bool/tinyint value: " . get_debug_type($value)
        );
    }
}
