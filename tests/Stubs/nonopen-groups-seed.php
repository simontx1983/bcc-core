<?php

/**
 * Seeds PeepSoGroupRepository::getNonOpenGroupIdsStrict()'s cache, so a
 * test that renders the global-feed SQL (F-23 gate) controls the
 * non-open group set without a database.
 *
 * bcc_test_seed_nonopen_groups([])     → "no group is non-open": the F-23
 *                                        clause adds nothing, SQL unchanged.
 * bcc_test_seed_nonopen_groups([7, 9]) → groups 7 and 9 are non-open.
 *
 * Builds on the shared in-memory object cache (object-cache-stubs.php).
 * Key shape mirrors PeepSoGroupRepository::nonOpenCacheKey() at the
 * default limit (500) and generation 0, plus the strict suffix.
 */

declare(strict_types=1);

require_once __DIR__ . '/object-cache-stubs.php';

if (!function_exists('bcc_test_seed_nonopen_groups')) {
    /** @param list<int> $ids */
    function bcc_test_seed_nonopen_groups(array $ids): void
    {
        \BccTestPersistentCache::$store['bcc_core:groups:non_open_gen'] = 0;
        \BccTestPersistentCache::$store['bcc_core:groups:non_open_group_ids:500:0:strict'] = $ids;
    }
}
