<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\ProbesReads;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * ProbesReads::trustedRowsAssoc() — the ARRAY_A sibling of trustedRows().
 *
 * Exists so reads written as `get_results($sql, ARRAY_A)` plus a
 * `last_error` check can delegate to the trait WITHOUT their consumers
 * changing row shape (F-472). Two things therefore have to hold:
 *
 *   1. on success it returns what `wpdb::get_results(ARRAY_A)` returns;
 *   2. on an untrusted read it returns null, including the pre-`flush()`
 *      bail that a `last_error` check cannot see.
 *
 * ⭐ (2) is the whole point. A caller that swapped in a method which merely
 * cast objects to arrays would keep the bug it was migrating away from.
 */
#[CoversTrait(ProbesReads::class)]
final class TrustedRowsAssocTest extends TestCase
{
    private object $wpdb;
    /** @var object a throwaway class that exposes the trait's private statics */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new class {
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            /** 'ok' | 'driver_error' | 'pre_flush_bail' */
            public string $mode = 'ok';
            /** @var list<mixed> rows handed back in 'ok' mode */
            public array $rows = [];
            public int $queries = 0;

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries++;

                if ($this->mode === 'driver_error') {
                    // flush() ran: last_result reset, last_error set.
                    $this->last_result = [];
                    $this->last_error  = 'Lost connection to MySQL server';
                    return false;
                }

                if ($this->mode === 'pre_flush_bail') {
                    // ⚠ false returned ABOVE flush(): last_result still holds
                    // the PREVIOUS query's rows, and last_error stays EMPTY.
                    $this->last_error = '';
                    return false;
                }

                $this->last_error  = '';
                $this->last_result = $this->rows;
                return is_array($this->rows) ? count($this->rows) : 0;
            }

            /**
             * Faithful ARRAY_A reader, for the equivalence test: it calls
             * query() and DISCARDS the return value, exactly as wpdb does.
             *
             * @return list<array<string, mixed>>
             */
            public function get_results_assoc(string $sql): array
            {
                $this->query($sql);
                $out = [];
                foreach ((array) $this->last_result as $row) {
                    $out[] = get_object_vars($row);
                }
                return $out;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        // The trait's helpers are private statics, so exercise them through a
        // class that uses the trait — the same way every real consumer does.
        $this->subject = new class {
            use ProbesReads;

            /** @return list<array<string, mixed>>|null */
            public static function read(string $sql): ?array
            {
                return self::trustedRowsAssoc($sql);
            }

            /** @return list<object>|null */
            public static function readObjects(string $sql): ?array
            {
                return self::trustedRows($sql);
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $rows */
    private function seed(array $rows): void
    {
        $this->wpdb->rows = array_map(static fn (array $r): object => (object) $r, $rows);
    }

    // ── success ────────────────────────────────────────────────────────

    public function testRowsComeBackAsAssociativeArrays(): void
    {
        $this->seed([
            ['ID' => '7', 'post_title' => 'first'],
            ['ID' => '9', 'post_title' => 'second'],
        ]);

        self::assertSame(
            [
                ['ID' => '7', 'post_title' => 'first'],
                ['ID' => '9', 'post_title' => 'second'],
            ],
            ($this->subject)::read('SELECT ...')
        );
    }

    /**
     * ⭐ Equivalence with the thing callers are migrating FROM. The stub's
     * reader performs wpdb's own ARRAY_A conversion (`get_object_vars` per
     * row), so this pins that a caller swapping one for the other sees no
     * difference on the success path.
     */
    public function testOnSuccessItMatchesWhatGetResultsArrayAWouldReturn(): void
    {
        $this->seed([
            ['a' => '1', 'b' => '', 'c' => null],
            ['a' => '2', 'b' => 'x', 'c' => '0'],
        ]);

        $viaWpdb = $this->wpdb->get_results_assoc('SELECT ...');
        $viaTrait = ($this->subject)::read('SELECT ...');

        self::assertSame($viaWpdb, $viaTrait);
    }

    /** A genuine empty result is [], never null — that distinction is the point. */
    public function testAnEmptyResultIsAnEmptyArrayNotNull(): void
    {
        $this->seed([]);

        self::assertSame([], ($this->subject)::read('SELECT ...'));
    }

    /** An empty-string value survives as an empty string, not a dropped key. */
    public function testEmptyStringValuesAreKept(): void
    {
        $this->seed([['meta_key' => 'k', 'meta_value' => '']]);

        self::assertSame([['meta_key' => 'k', 'meta_value' => '']], ($this->subject)::read('SELECT ...'));
    }

    // ── failure ────────────────────────────────────────────────────────

    public function testADriverErrorIsNull(): void
    {
        $this->wpdb->mode = 'driver_error';

        self::assertNull(($this->subject)::read('SELECT ...'));
    }

    /**
     * ⭐⭐ THE CASE A last_error CHECK CANNOT SEE. query() bails before
     * flush(), so last_result holds an unrelated query's rows and
     * last_error is empty. get_results(ARRAY_A) would hand those rows back
     * as this query's answer.
     */
    public function testThePreFlushBailIsNullEvenWithNoLastError(): void
    {
        $this->wpdb->last_result = [(object) ['ID' => '999', 'post_title' => 'someone else\'s row']];
        $this->wpdb->mode        = 'pre_flush_bail';

        self::assertNull(
            ($this->subject)::read('SELECT ...'),
            'rows from an unrelated query were returned as this query\'s result'
        );
        self::assertSame('', $this->wpdb->last_error, 'the premise: no error signal was set');
    }

    /**
     * ⚠ And the shape callers are migrating FROM really is fooled by it, so
     * the migration is worth something. Same stub, same state.
     */
    public function testTheOldShapeIsFooledByTheSamePreFlushBail(): void
    {
        $this->wpdb->last_result = [(object) ['ID' => '999']];
        $this->wpdb->mode        = 'pre_flush_bail';

        $old = $this->wpdb->get_results_assoc('SELECT ...');

        self::assertSame([['ID' => '999']], $old, 'the premise of F-472');
        self::assertSame('', $this->wpdb->last_error, 'and with no error to check');
    }

    // ── it is the same failure path as the object sibling ──────────────

    public function testBothShapesAgreeOnWhenAReadIsUntrusted(): void
    {
        foreach (['driver_error', 'pre_flush_bail'] as $mode) {
            $this->wpdb->mode = $mode;
            self::assertNull(($this->subject)::read('SELECT ...'), $mode);
            self::assertNull(($this->subject)::readObjects('SELECT ...'), $mode);
        }
    }

    public function testItRunsOneQuery(): void
    {
        $this->seed([['ID' => '1']]);

        ($this->subject)::read('SELECT ...');

        self::assertSame(1, $this->wpdb->queries);
    }
}
