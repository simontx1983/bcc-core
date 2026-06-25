<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Http\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Locks the request-correlation-id rules (Phase 4c): lazy mint, stable within a
 * request, client-id adoption with sanitisation (no control chars / injection),
 * and the test reset seam.
 */
final class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::reset();
    }

    public function testLazyMintsAndIsStableWithinARequest(): void
    {
        self::assertFalse(RequestContext::hasRequestId());
        $first = RequestContext::requestId();
        self::assertNotSame('', $first);
        self::assertSame($first, RequestContext::requestId(), 'id must be stable within a request');
        self::assertTrue(RequestContext::hasRequestId());
    }

    public function testAdoptsAClientSuppliedId(): void
    {
        self::assertTrue(RequestContext::setRequestId('abc-123_DEF.456'));
        self::assertSame('abc-123_DEF.456', RequestContext::requestId());
    }

    public function testSanitisesUnsafeCharactersFromClientId(): void
    {
        // Control chars / spaces / newlines (log-injection vectors) are stripped.
        self::assertTrue(RequestContext::setRequestId("ab c\n12\t3<x>"));
        self::assertSame('abc123x', RequestContext::requestId());
    }

    public function testRejectsAnIdThatSanitisesToEmpty(): void
    {
        self::assertFalse(RequestContext::setRequestId("\n\t <>"));
        self::assertFalse(RequestContext::hasRequestId(), 'a fully-stripped id must not be adopted');
    }

    public function testCapsIdLength(): void
    {
        RequestContext::setRequestId(str_repeat('a', 200));
        self::assertSame(64, strlen(RequestContext::requestId()));
    }
}
