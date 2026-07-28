<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Delegation pins for the transitional Local→Hall compat wrappers
 * (expand-and-contract prod cutover prep).
 *
 * Each of the six deprecated wrappers on PeepSoGroupRepository is a pure
 * pass-through to its Hall counterpart. We prove delegation without a live
 * DB by running the wrapper and the Hall method against the SAME recording
 * $wpdb fake and asserting they (a) return an identical value and (b) issue
 * a byte-identical prepared-query sequence — which only holds if the
 * wrapper actually calls through to the Hall method's query path.
 *
 * For listLocals / countLocals the wrapper keeps the OLD `?string $chain`
 * signature and delegates with a null chain filter; the test pins that the
 * wrapper's query sequence matches listHalls(null,...) / countHalls(null),
 * NOT any chain-filtered variant.
 *
 * Runs in its own subprocess; setUp() pulls in the $wpdb fake at its FQN so
 * the global fake never leaks into the main process.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'findOneBySlug')]
#[CoversMethod(PeepSoGroupRepository::class, 'findOneById')]
#[CoversMethod(PeepSoGroupRepository::class, 'getPrimaryLocalForUsers')]
#[CoversMethod(PeepSoGroupRepository::class, 'findUsersByPrimaryLocal')]
#[CoversMethod(PeepSoGroupRepository::class, 'listLocals')]
#[CoversMethod(PeepSoGroupRepository::class, 'countLocals')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallRepoCompatWrappersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/hall-compat-wpdb-stub.php';
        $this->freshWpdb();
    }

    /** Install a fresh recording $wpdb and return it. */
    private function freshWpdb(): \BccFakeWpdb
    {
        $wpdb = new \BccFakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testFindOneBySlugDelegatesToFindHallBySlug(): void
    {
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::findOneBySlug('ethereum-hall');

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::findHallBySlug('ethereum-hall');

        self::assertEquals($viaHall, $viaWrapper);
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
    }

    public function testFindOneByIdDelegatesToFindHallById(): void
    {
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::findOneById(42);

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::findHallById(42);

        self::assertEquals($viaHall, $viaWrapper);
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
    }

    public function testGetPrimaryLocalForUsersDelegatesToHall(): void
    {
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::getPrimaryLocalForUsers([5]);

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::getPrimaryHallForUsers([5]);

        self::assertEquals($viaHall, $viaWrapper);
        // Two-query path (usermeta pointer → findManyByIds) must match.
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
        // Delegation must read the Hall usermeta key, never the retired
        // bcc_primary_local_group_id literal.
        $joined = implode("\n", $wpdbWrapper->queries);
        self::assertStringContainsString('bcc_primary_hall_group_id', $joined);
        self::assertStringNotContainsString('bcc_primary_local_group_id', $joined);
    }

    public function testFindUsersByPrimaryLocalDelegatesToHall(): void
    {
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::findUsersByPrimaryLocal(42, 500);

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::findUsersByPrimaryHall(42, 500);

        self::assertSame($viaHall, $viaWrapper);
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
    }

    public function testListLocalsDelegatesToListHallsWithNullChain(): void
    {
        // Wrapper keeps the OLD ?string $chain signature; a non-null slug
        // must be ignored (delegates with null → no chain JOIN).
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::listLocals('ethereum', 0, 20);

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::listHalls(null, 0, 20);

        self::assertEquals($viaHall, $viaWrapper);
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
        // A null chain filter emits no chain-tag JOIN.
        self::assertStringNotContainsString('_bcc_chain_tag', $wpdbWrapper->queries[0]);
    }

    public function testCountLocalsDelegatesToCountHallsWithNullChain(): void
    {
        $wpdbWrapper = $this->freshWpdb();
        $viaWrapper  = PeepSoGroupRepository::countLocals('ethereum');

        $wpdbHall = $this->freshWpdb();
        $viaHall  = PeepSoGroupRepository::countHalls(null);

        self::assertSame($viaHall, $viaWrapper);
        self::assertSame(7, $viaWrapper);
        self::assertSame($wpdbHall->queries, $wpdbWrapper->queries);
        self::assertStringNotContainsString('_bcc_chain_tag', $wpdbWrapper->queries[0]);
    }
}
